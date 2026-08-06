<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Config;

use Magebit\Mcp\Model\Util\ConfigPathFormat;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Config\Model\ConfigFactory;
use Magento\Framework\App\Config\ScopeCodeResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;

/**
 * Persists one config path through the admin save path, so the field's backend model and validation
 * run exactly as they would in Stores > Configuration.
 */
class ConfigPathWriter
{
    private const MIN_SEGMENTS = 3;

    private const SCOPE_TYPES = [
        ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        ScopeInterface::SCOPE_WEBSITES,
        ScopeInterface::SCOPE_STORES,
    ];

    /**
     * @var array<string, true>|null
     */
    private ?array $declaredPaths = null;

    /**
     * @param ConfigFactory $configFactory
     * @param Structure $configStructure
     * @param ScopeConfigInterface $scopeConfig
     * @param SettingChecker $settingChecker
     * @param ScopeCodeResolver $scopeCodeResolver
     * @param ConfigPathFormat $pathFormat
     */
    public function __construct(
        private readonly ConfigFactory $configFactory,
        private readonly Structure $configStructure,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SettingChecker $settingChecker,
        private readonly ScopeCodeResolver $scopeCodeResolver,
        private readonly ConfigPathFormat $pathFormat
    ) {
    }

    /**
     * @param string $path
     * @param string $value
     * @param string $scope
     * @param string|null $scopeCode
     * @return void
     * @throws LocalizedException
     */
    public function write(string $path, string $value, string $scope, ?string $scopeCode): void
    {
        $parsed = $this->parse($path);
        if ($parsed === null) {
            throw new LocalizedException(
                __(
                    'Config path "%1" must be section/group/field: three or more slash-separated '
                    . 'segments of letters, digits and underscores.',
                    $this->pathFormat->forMessage($path)
                )
            );
        }

        $this->assertScope($scope, $scopeCode);

        $stored = $this->storedPath($path);
        if ($stored === null) {
            throw new LocalizedException(
                __(
                    'Config path "%1" is not declared as a field in system.xml. Writing it would '
                    . 'store a raw string with no backend model and no validation, so it is refused.',
                    $this->pathFormat->forMessage($path)
                )
            );
        }

        $this->assertNotRedirected($path, $stored);
        $this->assertNotLocked($path, $scope, $scopeCode);

        // Config is DI-wired to the plain area-scoped Structure, which is empty outside adminhtml.
        // Handing it the same instance the checks above used is what keeps the redirect resolution
        // and the save that follows it from resolving against two different structures.
        //
        // setSection()/setGroups()/... are DataObject __call magic, not declared methods; seeding
        // the same keys through the factory is equivalent and actually mockable.
        $this->configFactory->create([
            'configStructure' => $this->configStructure,
            'data' => [
                'section' => $parsed['section'],
                'website' => $scope === ScopeInterface::SCOPE_WEBSITES ? (string) $scopeCode : '',
                'store' => $scope === ScopeInterface::SCOPE_STORES ? (string) $scopeCode : '',
                'groups' => $this->buildGroups($parsed['groups'], $parsed['field'], $value),
            ],
        ])->save();
    }

    /**
     * @param string $path
     * @param string $scope
     * @param string|null $scopeCode
     * @return string|null
     * @throws LocalizedException
     */
    public function currentValue(string $path, string $scope, ?string $scopeCode): ?string
    {
        $this->assertScope($scope, $scopeCode);

        $value = $this->scopeConfig->getValue($path, $scope, $scopeCode);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * The section of the row that would actually be written, which is not the requested one when the
     * field redirects. Never throws: the caller resolves ACL resources with it, and the dispatcher
     * reads a throw out of that as a hard denial rather than the "no such field" the tool wants.
     *
     * @param string $path
     * @return Section|null
     */
    public function sectionFor(string $path): ?Section
    {
        $parsed = $this->parse($path);
        if ($parsed === null) {
            return null;
        }

        $sectionId = $parsed['section'];
        $stored = $this->parse($this->storedPath($path) ?? $path);
        if ($stored !== null) {
            $sectionId = $stored['section'];
        }

        $element = $this->configStructure->getElement($sectionId);
        if (!$element instanceof Section || $this->isSynthesisedSection($element, $sectionId)) {
            return null;
        }

        return $element;
    }

    /**
     * Magento overrides the posted path with the field's `<config_path>`, so an allowlisted path can
     * write a protected row. Nothing here re-checks the stored path against the policy: that would
     * put allowlist knowledge in the writer and hide the mismatch instead of reporting it.
     *
     * @param string $path
     * @param string $stored
     * @return void
     * @throws LocalizedException
     */
    private function assertNotRedirected(string $path, string $stored): void
    {
        if ($stored === $path) {
            return;
        }

        throw new LocalizedException(
            __(
                'Config path "%1" stores its value at "%2" instead, so writing it here would bypass '
                . 'every check made against "%1". Set it through Stores > Configuration.',
                $this->pathFormat->forMessage($path),
                $this->pathFormat->forMessage($stored)
            )
        );
    }

    /**
     * A path pinned in app/etc/env.php or by a CONFIG__* variable is skipped by _processGroup() and
     * save() still returns cleanly — the tool would report a change that never happened.
     *
     * @param string $path
     * @param string $scope
     * @param string|null $scopeCode
     * @return void
     * @throws LocalizedException
     */
    private function assertNotLocked(string $path, string $scope, ?string $scopeCode): void
    {
        // _processGroup() probes with Config::getScopeCode(), which is resolved. The env-variable
        // branch of isReadOnly() uses the code verbatim, so an id would probe CONFIG__WEBSITES__1__…
        // while the lock is CONFIG__WEBSITES__BASE__… and the write would be skipped in silence.
        $code = $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            ? null
            : $this->scopeCodeResolver->resolve($scope, $scopeCode);

        if (!$this->settingChecker->isReadOnly($path, $scope, $code)) {
            return;
        }

        throw new LocalizedException(
            __(
                'Config path "%1" is locked by app/etc/env.php or an environment variable in the "%2" '
                . 'scope. The save would be skipped silently, so it is refused instead.',
                $this->pathFormat->forMessage($path),
                $this->pathFormat->forMessage($scope)
            )
        );
    }

    /**
     * @param string $path A structural section/group/field path.
     * @return string|null Where the value is really stored, or null when no such field is declared.
     */
    private function storedPath(string $path): ?string
    {
        if (!isset($this->declaredPaths()[$path])) {
            return null;
        }

        // Byte-for-byte what Config::getFieldPath() reads, off the same flyweight, so the check and
        // the save that follows it always agree. Interception is per-area and this module's route is
        // not adminhtml, so an adminhtml-only plugin is invisible to both alike — Magento_Paypal's
        // payment_<country> rewrite is why those sections are protected in di.xml instead.
        $element = $this->configStructure->getElement($path);
        $configPath = $element instanceof Field ? (string) $element->getConfigPath() : '';

        return $configPath !== '' && strrpos($configPath, '/') > 0 ? $configPath : $path;
    }

    /**
     * Existence only. Structure::getFieldPaths() walks the raw merged array, which is exact for
     * "does system.xml declare this field" but blind to plugins — hence the flyweight above.
     *
     * @return array<string, true>
     */
    private function declaredPaths(): array
    {
        if ($this->declaredPaths !== null) {
            return $this->declaredPaths;
        }

        $declared = [];
        foreach ($this->configStructure->getFieldPaths() as $structurePaths) {
            if (!is_array($structurePaths)) {
                continue;
            }
            foreach ($structurePaths as $structurePath) {
                if (is_string($structurePath)) {
                    $declared[$structurePath] = true;
                }
            }
        }

        return $this->declaredPaths = $declared;
    }

    /**
     * Structure::getElement() answers an undeclared id with a synthesised placeholder rather than
     * null; it carries exactly `id`, `path` and `_elementType`, and a real section has no `path`.
     *
     * @param Section $section
     * @param string $expectedId
     * @return bool
     */
    private function isSynthesisedSection(Section $section, string $expectedId): bool
    {
        $data = $section->getData();

        return count($data) === 3
            && ($data['id'] ?? null) === $expectedId
            && array_key_exists('path', $data)
            && isset($data['_elementType']);
    }

    /**
     * @param string $scope
     * @param string|null $scopeCode
     * @return void
     * @throws LocalizedException
     */
    private function assertScope(string $scope, ?string $scopeCode): void
    {
        if (!in_array($scope, self::SCOPE_TYPES, true)) {
            throw new LocalizedException(
                __(
                    'Unknown configuration scope "%1". Expected one of: %2.',
                    $this->pathFormat->forMessage($scope),
                    implode(', ', self::SCOPE_TYPES)
                )
            );
        }

        if ($scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT && (string) $scopeCode === '') {
            throw new LocalizedException(
                __('Scope "%1" needs a scope code; without one the call would fall back to the default scope.', $scope)
            );
        }
    }

    /**
     * Nested groups nest a further `groups` key, matching what the admin form posts.
     *
     * @param list<string> $groupSegments
     * @param string $field
     * @param string $value
     * @return array<string, mixed>
     */
    private function buildGroups(array $groupSegments, string $field, string $value): array
    {
        $node = ['fields' => [$field => ['value' => $value]]];

        foreach (array_reverse($groupSegments) as $index => $group) {
            $node = $index === 0 ? [$group => $node] : [$group => ['groups' => $node]];
        }

        return $node;
    }

    /**
     * @param string $path
     * @return array{section: string, groups: list<string>, field: string}|null
     */
    private function parse(string $path): ?array
    {
        if (!$this->pathFormat->isCanonical($path)) {
            return null;
        }

        $segments = explode('/', $path);
        if (count($segments) < self::MIN_SEGMENTS) {
            return null;
        }

        return [
            'section' => $segments[0],
            'groups' => array_slice($segments, 1, count($segments) - 2),
            'field' => $segments[count($segments) - 1],
        ];
    }
}
