<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Config;

use Magebit\Mcp\Model\Util\ConfigPathFormat;
use Magento\Config\Model\Config\Backend\File;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Config\Model\ConfigFactory;
use Magento\Framework\App\Config\ScopeCodeResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ScopeInterface as AppScopeInterface;
use Magento\Framework\App\ScopeResolverPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\ScopeInterface;

/**
 * Persists one config path through the admin save path, so the field's backend model and validation
 * run exactly as they would in Stores > Configuration.
 *
 * A dry run ({@see self::validate()}) makes the same refusals and runs the field's backend model up to the point
 * where the save would write: validateBeforeSave() and beforeSave(), never save(), afterSave() or the resource
 * model. Two kinds of check are therefore not covered: a backend that validates only in afterSave() (the currency
 * backends Currency\Allow, Base and DefaultCurrency do), and a backend listed in `dryRunSkippedBackends` (di.xml),
 * whose beforeSave() changes something outside the model itself, so its value is not checked in a dry run.
 */
class ConfigPathWriter
{
    private const MIN_SEGMENTS = 3;

    private const FILE_FIELD_TYPES = ['image', 'file'];

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
     * @param ScopeResolverPool $scopeResolverPool
     * @param array<int|string, string> $dryRunSkippedBackends Backend model classes (subclasses included) whose
     *                                                         beforeSave() has side effects; a dry run skips them.
     */
    public function __construct(
        private readonly ConfigFactory $configFactory,
        private readonly Structure $configStructure,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SettingChecker $settingChecker,
        private readonly ScopeCodeResolver $scopeCodeResolver,
        private readonly ConfigPathFormat $pathFormat,
        private readonly ScopeResolverPool $scopeResolverPool,
        private readonly array $dryRunSkippedBackends = []
    ) {
    }

    /**
     * Every check write() makes, without saving: the shape of the path, the scope, the system.xml declaration,
     * redirects, file uploads and env locks, then the scope and the field's backend model the way the save runs
     * them. A dry run calls only this, so a value the save would refuse is refused here with the same exception.
     *
     * @param string $path
     * @param string $value
     * @param string $scope
     * @param string|null $scopeCode
     * @return void
     * @throws LocalizedException
     */
    public function validate(string $path, string $value, string $scope, ?string $scopeCode): void
    {
        $parsed = $this->validatedParts($path, $scope, $scopeCode);
        $this->validateValue($path, $parsed, $value, $scope, $scopeCode);
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
        // The same refusals validate() makes, in one pass.
        $parsed = $this->validatedParts($path, $scope, $scopeCode);

        // Handing Config the same structure keeps these checks and the save that follows from
        // resolving against two different ones; the data keys are what setSection()/setGroups() set.
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
     * What Config::save() does for this one field up to the row write: resolve the scope (Config::initScope()),
     * build the backend model and hand it the data Config::_processGroup() hands it, then the two checks
     * AbstractDb::save() runs before it writes. A field without a backend model gets a plain config value, which
     * checks nothing, so there is nothing to run.
     *
     * @param string $path
     * @param array{section: string, groups: list<string>, field: string} $parsed
     * @param string $value
     * @param string $scope
     * @param string|null $scopeCode
     * @return void
     * @throws LocalizedException
     */
    private function validateValue(string $path, array $parsed, string $value, string $scope, ?string $scopeCode): void
    {
        $resolved = $this->resolvedScope($scope, $scopeCode);

        $field = $this->configStructure->getElement($path);
        if (!$field instanceof Field || !$field->hasBackendModel()) {
            return;
        }
        $backend = $field->getBackendModel();
        if (!$backend instanceof AbstractModel || $this->isSkippedInDryRun($backend)) {
            return;
        }

        $groupIds = $parsed['groups'];
        $backend->addData([
            'field' => $parsed['field'],
            'groups' => $this->buildGroups($groupIds, $parsed['field'], $value),
            'group_id' => $groupIds[count($groupIds) - 1] ?? '',
            'scope' => $scope,
            'scope_id' => $resolved->getId(),
            'scope_code' => $resolved->getCode(),
            'field_config' => $field->getData(),
            'fieldset_data' => [$parsed['field'] => $value],
        ]);
        $backend->setData('path', $path);
        $backend->setData('value', $value);
        $backend->validateBeforeSave();
        $backend->beforeSave();
    }

    /**
     * The id of the scope a write would land in, resolved the way the save resolves it, so an id and a code of the
     * same website or store give the same answer. The default scope is 0. Never throws: null when the scope type is
     * unknown, a website or store scope has no code (the resolver would read that as the current store), or the
     * code names nothing; the write refuses those items itself.
     *
     * @param string $scope
     * @param string|null $scopeCode
     * @return int|null
     */
    public function canonicalScopeId(string $scope, ?string $scopeCode): ?int
    {
        if ($scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
            return 0;
        }
        if (!in_array($scope, self::SCOPE_TYPES, true) || (string) $scopeCode === '') {
            return null;
        }
        try {
            // Typed int, but a website or store model hands back the row's id as read from the database.
            return (int) $this->resolvedScope($scope, $scopeCode)->getId();
        } catch (LocalizedException | \InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Config::initScope()'s resolution.
     *
     * @param string $scope
     * @param string|null $scopeCode
     * @return AppScopeInterface
     */
    private function resolvedScope(string $scope, ?string $scopeCode): AppScopeInterface
    {
        $isDefault = $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        // Config::retrieveScope() hands the resolvers a code too, although their docblock types an id.
        // @phpstan-ignore argument.type
        return $this->scopeResolverPool->get($scope)->getScope($isDefault ? null : $scopeCode);
    }

    /**
     * @param AbstractModel $backend
     * @return bool
     */
    private function isSkippedInDryRun(AbstractModel $backend): bool
    {
        foreach ($this->dryRunSkippedBackends as $class) {
            if (is_a($backend, ltrim($class, '\\'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $path
     * @param string $scope
     * @param string|null $scopeCode
     * @return array{section: string, groups: list<string>, field: string}
     * @throws LocalizedException
     */
    private function validatedParts(string $path, string $scope, ?string $scopeCode): array
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
        $this->assertNotFileUpload($path);
        $this->assertNotLocked($path, $scope, $scopeCode);

        return $parsed;
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
     * Backend\File::beforeSave() reads $_FILES only: handed a plain string it unsets the value, so
     * the row is left untouched or created NULL while save() still returns cleanly.
     *
     * @param string $path
     * @return void
     * @throws LocalizedException
     */
    private function assertNotFileUpload(string $path): void
    {
        $element = $this->configStructure->getElement($path);
        if (!$element instanceof Field || !$this->isFileUploadField($element)) {
            return;
        }

        throw new LocalizedException(
            __(
                'Config path "%1" takes a file upload, so a plain value would be discarded while the '
                . 'save still reported success. Set it through Stores > Configuration.',
                $this->pathFormat->forMessage($path)
            )
        );
    }

    /**
     * The backend model is the reliable signal; the declared type is the secondary catch, since a
     * third-party field may carry either one alone.
     *
     * @param Field $field
     * @return bool
     */
    private function isFileUploadField(Field $field): bool
    {
        $backend = $field->getAttribute('backend_model');
        if (is_string($backend) && $backend !== '' && is_a(ltrim($backend, '\\'), File::class, true)) {
            return true;
        }

        return in_array(strtolower((string) $field->getType()), self::FILE_FIELD_TYPES, true);
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
        // _processGroup() probes with a resolved scope code and isReadOnly() uses it verbatim, so an
        // id would probe CONFIG__WEBSITES__1__… while the lock is …__BASE__… and be skipped in silence.
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
        // the save always agree. Adminhtml-only plugins are invisible to both — hence the di.xml list.
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
