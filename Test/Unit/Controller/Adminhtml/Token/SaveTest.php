<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Controller\Adminhtml\Token;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Controller\Adminhtml\Token\Save;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Adminhtml\FormDataPersistence;
use Magebit\Mcp\Model\Auth\AdminUserLookup;
use Magebit\Mcp\Model\Auth\TokenGenerator;
use Magebit\Mcp\Model\Auth\TokenHasher;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenFactory;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session;
use Magento\Backend\Model\View\Result\Page;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title as PageTitle;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SaveTest extends TestCase
{
    /**
     * @phpstan-var Context&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private Context&MockObject $context;

    /**
     * @phpstan-var HttpRequest&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private HttpRequest&MockObject $request;

    /**
     * @phpstan-var MessageManagerInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private MessageManagerInterface&MockObject $messageManager;

    /**
     * @phpstan-var Session&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private Session&MockObject $session;

    /**
     * @phpstan-var ResultFactory&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ResultFactory&MockObject $resultFactory;

    /**
     * @phpstan-var Redirect&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private Redirect&MockObject $redirect;

    /**
     * @phpstan-var AdminUserLookup&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AdminUserLookup&MockObject $adminUserLookup;

    /**
     * @phpstan-var TokenFactory&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenFactory&MockObject $tokenFactory;

    /**
     * @phpstan-var TokenGenerator&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenGenerator&MockObject $tokenGenerator;

    /**
     * @phpstan-var TokenHasher&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenHasher&MockObject $tokenHasher;

    /**
     * @phpstan-var TokenRepository&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private TokenRepository&MockObject $tokenRepository;

    /**
     * @phpstan-var ToolRegistryInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private ToolRegistryInterface&MockObject $toolRegistry;

    /**
     * @phpstan-var AclChecker&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private AclChecker&MockObject $aclChecker;

    /**
     * @phpstan-var FormDataPersistence&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private FormDataPersistence&MockObject $formDataPersistence;

    private Save $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->messageManager = $this->createMock(MessageManagerInterface::class);
        $this->session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->addMethods(['setData'])
            ->getMock();
        $this->redirect = $this->getMockBuilder(Redirect::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setPath'])
            ->getMock();
        $this->redirect->method('setPath')->willReturnSelf();

        $pageTitle = $this->createMock(PageTitle::class);
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($pageTitle);
        $layout = $this->createMock(LayoutInterface::class);

        $page = $this->getMockBuilder(Page::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setActiveMenu', 'getConfig', 'setHeader', 'getLayout'])
            ->getMock();
        $page->method('setActiveMenu')->willReturnSelf();
        $page->method('getConfig')->willReturn($pageConfig);
        $page->method('setHeader')->willReturnSelf();
        $page->method('getLayout')->willReturn($layout);

        $this->resultFactory = $this->createMock(ResultFactory::class);
        $this->resultFactory->method('create')->willReturnCallback(
            fn (string $type) => $type === ResultFactory::TYPE_PAGE ? $page : $this->redirect
        );

        $this->context = $this->createMock(Context::class);
        $this->context->method('getRequest')->willReturn($this->request);
        $this->context->method('getMessageManager')->willReturn($this->messageManager);
        $this->context->method('getSession')->willReturn($this->session);
        $this->context->method('getResultFactory')->willReturn($this->resultFactory);

        $this->adminUserLookup = $this->createMock(AdminUserLookup::class);
        $this->tokenFactory = $this->getMockBuilder(TokenFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->tokenGenerator = $this->createMock(TokenGenerator::class);
        $this->tokenHasher = $this->createMock(TokenHasher::class);
        $this->tokenRepository = $this->createMock(TokenRepository::class);
        $this->toolRegistry = $this->createMock(ToolRegistryInterface::class);
        $this->aclChecker = $this->createMock(AclChecker::class);
        $this->formDataPersistence = $this->createMock(FormDataPersistence::class);

        $this->controller = new Save(
            $this->context,
            $this->adminUserLookup,
            $this->tokenFactory,
            $this->tokenGenerator,
            $this->tokenHasher,
            $this->tokenRepository,
            $this->toolRegistry,
            $this->aclChecker,
            $this->formDataPersistence
        );
    }

    public function testAllResourcesYieldsNullScopes(): void
    {
        $this->primeRequest([
            'admin_user_id' => '7',
            'name' => 'My Token',
            'expires_at' => '',
            'allow_writes' => '1',
            'all_resources' => '1',
        ]);
        $this->primeAdmin(7);
        $this->tokenGenerator->method('generate')->willReturn('plaintext');
        $this->tokenHasher->method('hash')->willReturn('hash');

        $token = $this->createMock(Token::class);
        $token->method('getId')->willReturn(123);
        $this->tokenFactory->method('create')->willReturn($token);
        $token->expects($this->once())->method('setScopes')->with(null);

        $this->aclChecker->expects($this->never())->method('isAllowed');
        $this->tokenRepository->expects($this->once())->method('save')->with($token);

        $this->controller->execute();
    }

    public function testCustomResourcesTranslatedToToolNames(): void
    {
        $this->primeRequest([
            'admin_user_id' => '7',
            'name' => 'Scoped Token',
            'expires_at' => '',
            'allow_writes' => '0',
            'all_resources' => '0',
            'resource' => [
                'Magebit_Mcp::tool_system_store_list',
                'Magebit_Mcp::tool_system_store_info',
            ],
        ]);
        $this->primeAdmin(7);
        $this->aclChecker->method('isAllowed')->willReturn(true);

        $this->toolRegistry->method('all')->willReturn([
            $this->makeTool('system.store.list', 'Magebit_Mcp::tool_system_store_list'),
            $this->makeTool('system.store.info', 'Magebit_Mcp::tool_system_store_info'),
        ]);

        $this->tokenGenerator->method('generate')->willReturn('plaintext');
        $this->tokenHasher->method('hash')->willReturn('hash');

        $token = $this->createMock(Token::class);
        $token->method('getId')->willReturn(456);
        $this->tokenFactory->method('create')->willReturn($token);
        $token->expects($this->once())
            ->method('setScopes')
            ->with(['system.store.list', 'system.store.info']);

        $this->tokenRepository->expects($this->once())->method('save')->with($token);

        $this->controller->execute();
    }

    public function testRejectsResourceOutsideAdminRole(): void
    {
        $this->primeRequest([
            'admin_user_id' => '7',
            'name' => 'Bad Token',
            'expires_at' => '',
            'allow_writes' => '0',
            'all_resources' => '0',
            'resource' => ['Magebit_Mcp::tool_system_store_list'],
        ]);
        $this->primeAdmin(7);
        $this->aclChecker->method('isAllowed')->willReturn(false);
        $this->toolRegistry->method('all')->willReturn([
            $this->makeTool('system.store.list', 'Magebit_Mcp::tool_system_store_list'),
        ]);

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->stringContains('does not allow Magebit_Mcp::tool_system_store_list'));
        $this->tokenRepository->expects($this->never())->method('save');
        $this->formDataPersistence->expects($this->atLeastOnce())
            ->method('set')
            ->with($this->isType('array'));

        $this->controller->execute();
    }

    public function testUnknownAclIdGetsDroppedSilently(): void
    {
        $this->primeRequest([
            'admin_user_id' => '7',
            'name' => 'Sparse Token',
            'expires_at' => '',
            'allow_writes' => '0',
            'all_resources' => '0',
            'resource' => [
                'Magebit_Mcp::tool_system_store_list',
                'Magebit_Mcp::tool_orphaned_acl',
            ],
        ]);
        $this->primeAdmin(7);
        $this->aclChecker->method('isAllowed')->willReturn(true);
        $this->toolRegistry->method('all')->willReturn([
            $this->makeTool('system.store.list', 'Magebit_Mcp::tool_system_store_list'),
        ]);

        $this->tokenGenerator->method('generate')->willReturn('plaintext');
        $this->tokenHasher->method('hash')->willReturn('hash');

        $token = $this->createMock(Token::class);
        $token->method('getId')->willReturn(789);
        $this->tokenFactory->method('create')->willReturn($token);
        $token->expects($this->once())
            ->method('setScopes')
            ->with(['system.store.list']);

        $this->tokenRepository->expects($this->once())->method('save');

        $this->controller->execute();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function primeRequest(array $values): void
    {
        $this->request->method('getPostValue')->willReturn($values);
    }

    private function primeAdmin(int $id): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('getIsActive')->willReturn(1);
        $this->adminUserLookup->method('getById')->with($id)->willReturn($admin);
    }

    private function makeTool(string $name, string $aclResource): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('getAclResource')->willReturn($aclResource);
        return $tool;
    }
}
