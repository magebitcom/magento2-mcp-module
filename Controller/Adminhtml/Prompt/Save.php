<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Controller\Adminhtml\Prompt;

use InvalidArgumentException;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Model\Adminhtml\FormDataPersistence;
use Magebit\Mcp\Model\Prompt\AdminPrompt;
use Magebit\Mcp\Model\Prompt\AdminPromptFactory;
use Magebit\Mcp\Model\Prompt\AdminPromptRepository;
use Magebit\Mcp\Model\Prompt\Validation\AdminPromptValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\User;
use Throwable;

/**
 * POST `magebit_mcp/prompt/save` — create + edit. The `custom.` prefix is forced
 * server-side so the form only carries the suffix; if someone submits a full
 * name it is normalised before validation.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_Mcp::mcp_prompts';

    /**
     * @param Context $context
     * @param AdminPromptFactory $promptFactory
     * @param AdminPromptRepository $repository
     * @param AdminPromptValidator $validator
     * @param FormDataPersistence $formDataPersistence
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly AdminPromptFactory $promptFactory,
        private readonly AdminPromptRepository $repository,
        private readonly AdminPromptValidator $validator,
        private readonly FormDataPersistence $formDataPersistence,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        /** @var HttpRequest $request */
        $request = $this->getRequest();
        $rawAny = $request->getPostValue();
        if (!is_array($rawAny) || $rawAny === []) {
            $this->messageManager->addErrorMessage((string) __('Missing form payload.'));
            return $redirect->setPath('*/*/index');
        }
        $raw = [];
        foreach ($rawAny as $key => $value) {
            if (is_string($key)) {
                $raw[$key] = $value;
            }
        }

        $idRaw = $raw['id'] ?? 0;
        $editingId = is_scalar($idRaw) ? (int) $idRaw : 0;

        $payload = $this->extractPayload($raw);

        $prompt = $this->loadOrCreate($editingId);
        if ($prompt === null) {
            return $redirect->setPath('*/*/index');
        }

        $prompt->setName($payload['name']);
        $prompt->setTitle($payload['title']);
        $prompt->setDescription($payload['description']);
        $prompt->setBody($payload['body']);
        $prompt->setArguments($payload['arguments']);
        $prompt->setRequiresWrite($payload['requires_write']);
        $prompt->setIsActive($payload['is_active']);

        try {
            $this->validator->validate($prompt);
        } catch (InvalidArgumentException $e) {
            $this->preserveFormData($raw);
            $this->messageManager->addErrorMessage($e->getMessage());
            return $editingId > 0
                ? $redirect->setPath('*/*/edit', ['id' => $editingId])
                : $redirect->setPath('*/*/new');
        }

        try {
            $this->repository->save($prompt);
        } catch (Throwable $e) {
            $this->preserveFormData($raw);
            $this->logger->error('Admin prompt save failed.', [
                'prompt_name' => $prompt->getName(),
                'admin_user_id' => $this->resolveAdminUserId(),
                'exception' => $e,
            ]);
            $this->messageManager->addErrorMessage(
                (string) __('Failed to save prompt: %1', $e->getMessage())
            );
            return $editingId > 0
                ? $redirect->setPath('*/*/edit', ['id' => $editingId])
                : $redirect->setPath('*/*/new');
        }

        $this->logger->info(
            $editingId > 0 ? 'Admin prompt updated.' : 'Admin prompt created.',
            [
                'prompt_name' => $prompt->getName(),
                'admin_user_id' => $this->resolveAdminUserId(),
                'action' => $editingId > 0 ? 'update' : 'create',
            ]
        );

        $this->messageManager->addSuccessMessage(
            (string) __('Prompt "%1" saved.', $prompt->getName())
        );
        return $redirect->setPath('*/*/index');
    }

    /**
     * @param int $editingId
     * @return AdminPrompt|null
     */
    private function loadOrCreate(int $editingId): ?AdminPrompt
    {
        if ($editingId === 0) {
            return $this->promptFactory->create();
        }
        try {
            return $this->repository->getById($editingId);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(
                (string) __('Prompt #%1 no longer exists.', $editingId)
            );
            return null;
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{
     *     name: string,
     *     title: string,
     *     description: string,
     *     body: string,
     *     arguments: array<int, array{name: string, description: string, required: bool}>,
     *     requires_write: bool,
     *     is_active: bool
     * }
     */
    private function extractPayload(array $raw): array
    {
        $suffix = $this->scalarString($raw['name_suffix'] ?? '');
        $suffix = trim($suffix);
        // Strip a stray prefix if the admin typed the full name. The validator
        // will catch invalid characters either way.
        if (str_starts_with($suffix, AdminPrompt::NAME_PREFIX)) {
            $suffix = substr($suffix, strlen(AdminPrompt::NAME_PREFIX));
        }
        $name = $suffix === '' ? '' : AdminPrompt::NAME_PREFIX . $suffix;

        return [
            'name' => $name,
            'title' => trim($this->scalarString($raw['title'] ?? '')),
            'description' => trim($this->scalarString($raw['description'] ?? '')),
            'body' => $this->scalarString($raw['body'] ?? ''),
            'arguments' => $this->extractArguments($raw['arguments'] ?? null),
            'requires_write' => $this->checkboxOn($raw['requires_write'] ?? null),
            'is_active' => $this->checkboxOn($raw['is_active'] ?? null, defaultOn: true),
        ];
    }

    /**
     * Form rows can arrive as either a list (when rendered as `name[]`) or an
     * object keyed by stable row ids; this normalises both.
     *
     * @param mixed $raw
     * @return array<int, array{name: string, description: string, required: bool}>
     */
    private function extractArguments(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim($this->scalarString($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $description = trim($this->scalarString($row['description'] ?? ''));
            $required = $this->checkboxOn($row['required'] ?? null);
            $out[] = [
                'name' => $name,
                'description' => $description,
                'required' => $required,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     * @return void
     */
    private function preserveFormData(array $raw): void
    {
        $this->formDataPersistence->set([
            'name_suffix' => $raw['name_suffix'] ?? '',
            'title' => $raw['title'] ?? '',
            'description' => $raw['description'] ?? '',
            'body' => $raw['body'] ?? '',
            'arguments' => is_array($raw['arguments'] ?? null) ? $raw['arguments'] : [],
            'requires_write' => $this->checkboxOn($raw['requires_write'] ?? null) ? '1' : '0',
            'is_active' => $this->checkboxOn($raw['is_active'] ?? null, defaultOn: true) ? '1' : '0',
        ]);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function scalarString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param mixed $value
     * @param bool $defaultOn When true, missing input means on (used for is_active).
     * @return bool
     */
    private function checkboxOn(mixed $value, bool $defaultOn = false): bool
    {
        if ($value === null) {
            return $defaultOn;
        }
        if (!is_scalar($value)) {
            return false;
        }
        $string = (string) $value;
        return $string === '1' || $string === 'on' || $string === 'true';
    }

    /**
     * @return int|null
     */
    private function resolveAdminUserId(): ?int
    {
        $user = $this->_auth->getUser();
        if (!$user instanceof User) {
            return null;
        }
        $raw = $user->getId();
        return is_scalar($raw) ? (int) $raw : null;
    }
}
