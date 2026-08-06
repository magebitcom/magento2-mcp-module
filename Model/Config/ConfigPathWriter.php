<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Model\Config;

use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Config\Model\ConfigFactory;
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

    private const MESSAGE_PATH_MAX_LENGTH = 120;

    /**
     * Both the singular and plural spellings are accepted because the framework's own
     * ScopeConfigInterface::getValue() normalises them, so callers legitimately use either.
     */
    private const SCOPE_ALIASES = [
        'default' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        'website' => ScopeInterface::SCOPE_WEBSITES,
        'websites' => ScopeInterface::SCOPE_WEBSITES,
        'store' => ScopeInterface::SCOPE_STORES,
        'stores' => ScopeInterface::SCOPE_STORES,
    ];

    /**
     * @param ConfigFactory $configFactory
     * @param Structure $configStructure
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ConfigFactory $configFactory,
        private readonly Structure $configStructure,
        private readonly ScopeConfigInterface $scopeConfig
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
                    'Config path "%1" must have at least three segments (section/group/field), each '
                    . 'non-empty and free of whitespace.',
                    $this->forMessage($path)
                )
            );
        }

        $normalizedScope = $this->normalizeScope($scope);
        $code = (string) $scopeCode;
        if ($normalizedScope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT && $code === '') {
            throw new LocalizedException(
                __('Scope "%1" needs a scope code; without one the write would land on the default scope.', $scope)
            );
        }

        // setSection()/setGroups()/... are DataObject __call magic, not declared methods; seeding
        // the same keys through the factory is equivalent and actually mockable.
        $this->configFactory->create([
            'data' => [
                'section' => $parsed['section'],
                'website' => $normalizedScope === ScopeInterface::SCOPE_WEBSITES ? $code : '',
                'store' => $normalizedScope === ScopeInterface::SCOPE_STORES ? $code : '',
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
        $value = $this->scopeConfig->getValue($path, $this->normalizeScope($scope), $scopeCode);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Never throws: the caller resolves ACL resources with it, and the dispatcher reads a throw out
     * of that as a hard denial rather than the "no such field" message the tool wants to report.
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

        $element = $this->configStructure->getElement($parsed['section']);
        if (!$element instanceof Section || $this->isPlaceholder($element, $parsed['section'])) {
            return null;
        }

        return $element;
    }

    /**
     * Structure::getElement() answers an undeclared id with a synthesised three-key element instead
     * of null; a section declared in system.xml always carries more than that and never a `path`.
     *
     * @param Section $section
     * @param string $sectionId
     * @return bool
     */
    private function isPlaceholder(Section $section, string $sectionId): bool
    {
        $data = $section->getData();

        return count($data) === 3
            && ($data['id'] ?? null) === $sectionId
            && ($data['path'] ?? null) === ''
            && ($data['_elementType'] ?? null) === 'section';
    }

    /**
     * @param string $scope
     * @return string
     * @throws LocalizedException
     */
    private function normalizeScope(string $scope): string
    {
        if (!isset(self::SCOPE_ALIASES[$scope])) {
            throw new LocalizedException(
                __(
                    'Unknown configuration scope "%1". Expected one of: %2.',
                    $this->forMessage($scope),
                    implode(', ', array_keys(self::SCOPE_ALIASES))
                )
            );
        }

        return self::SCOPE_ALIASES[$scope];
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
     * Non-canonical input is refused rather than normalised, so a caller cannot reach a path
     * different from the one an earlier allowlist check approved.
     *
     * @param string $path
     * @return array{section: string, groups: list<string>, field: string}|null
     */
    private function parse(string $path): ?array
    {
        $segments = explode('/', $path);
        if (count($segments) < self::MIN_SEGMENTS) {
            return null;
        }

        foreach ($segments as $segment) {
            if ($segment === '' || preg_match('/\s/', $segment) === 1) {
                return null;
            }
        }

        return [
            'section' => $segments[0],
            'groups' => array_slice($segments, 1, count($segments) - 2),
            'field' => $segments[count($segments) - 1],
        ];
    }

    /**
     * The rejection message travels to the JSON-RPC error string and the audit log, so echo back
     * something bounded and printable even when the input never passed a shape check.
     *
     * @param string $value
     * @return string
     */
    private function forMessage(string $value): string
    {
        $safe = preg_replace('/[[:cntrl:]]+/', ' ', $value);
        if (!is_string($safe)) {
            return '';
        }

        $safe = mb_convert_encoding($safe, 'UTF-8', 'UTF-8');

        return mb_strlen($safe) > self::MESSAGE_PATH_MAX_LENGTH
            ? mb_substr($safe, 0, self::MESSAGE_PATH_MAX_LENGTH) . '...'
            : $safe;
    }
}
