<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Controller\Adminhtml\Token;

use Magebit\Mcp\Controller\Adminhtml\Token\Delete;
use Magebit\Mcp\Controller\Adminhtml\Token\MassDelete;
use Magebit\Mcp\Model\Job\JobCanceller;
use Magebit\Mcp\Model\Job\JobConnection;
use Magebit\Mcp\Model\ResourceModel\Token\Collection;
use Magebit\Mcp\Model\ResourceModel\Token\CollectionFactory;
use Magebit\Mcp\Model\Token;
use Magebit\Mcp\Model\TokenRepository;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Ui\Component\MassAction\Filter;
use PHPUnit\Framework\TestCase;

/**
 * Review M2: deleting a token cancels its jobs first, while the job rows still point at it.
 */
class DeleteTest extends TestCase
{
    public function testDeleteCancelsJobsBeforeTheRowGoes(): void
    {
        $calls = [];
        $token = $this->token(5);
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->with(5)->willReturn($token);
        $repo->expects(self::once())->method('deleteById')->with(5)
            ->willReturnCallback(function () use (&$calls): void {
                $calls[] = 'delete';
            });
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::once())->method('cancelForRevokedToken')->with($token)
            ->willReturnCallback(function () use (&$calls): int {
                $calls[] = 'cancel';
                return 2;
            });
        $messages = $this->createMock(MessageManagerInterface::class);
        $messages->expects(self::once())->method('addSuccessMessage')
            ->with('Connection #5 deleted. 2 background job(s) were cancelled.');

        $this->deleteController($repo, $canceller, $messages)->execute();

        self::assertSame(['cancel', 'delete'], $calls);
    }

    public function testDeleteOfRevokedTokenDoesNotCancelJobs(): void
    {
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->willReturn($this->token(5, '2026-11-01 00:00:00'));
        $repo->expects(self::once())->method('deleteById')->with(5);
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::never())->method('cancelForRevokedToken');
        $messages = $this->createMock(MessageManagerInterface::class);
        $messages->expects(self::once())->method('addSuccessMessage')->with('Connection #5 deleted.');

        $this->deleteController($repo, $canceller, $messages)->execute();
    }

    public function testDeleteNotFoundCancelsNothing(): void
    {
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->willThrowException(NoSuchEntityException::singleField('id', 5));
        $repo->expects(self::never())->method('deleteById');
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::never())->method('cancelForRevokedToken');
        $messages = $this->createMock(MessageManagerInterface::class);
        $messages->expects(self::once())->method('addErrorMessage')->with('Connection #5 not found.');

        $this->deleteController($repo, $canceller, $messages)->execute();
    }

    public function testMassDeleteCancelsJobsOfLiveTokensOnly(): void
    {
        $calls = [];
        $live = $this->token(5);
        $revoked = $this->token(6, '2026-11-01 00:00:00');
        $liveOAuth = $this->token(7, null, 4);
        $collection = $this->createMock(Collection::class);
        $collection->method('getItems')->willReturn([$live, $revoked, $liveOAuth]);
        $filter = $this->createMock(Filter::class);
        $filter->method('getCollection')->willReturn($collection);
        $factory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($collection);
        $repo = $this->createMock(TokenRepository::class);
        $repo->expects(self::exactly(3))->method('deleteById')
            ->willReturnCallback(function (int $id) use (&$calls): void {
                $calls[] = 'delete ' . $id;
            });
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::never())->method('cancelForRevokedToken');
        // PR #48 review round 5 (S2): one batched call for the live tokens, before any row is deleted.
        $canceller->expects(self::once())->method('cancelForConnections')
            ->with([JobConnection::personal(5), JobConnection::oauth(0, 4)])
            ->willReturnCallback(function () use (&$calls): int {
                $calls[] = 'cancel';
                return 1;
            });
        $messages = $this->createMock(MessageManagerInterface::class);
        $messages->expects(self::once())->method('addSuccessMessage')
            ->with('Deleted 3 connection(s). 1 background job(s) were cancelled.');

        $controller = new MassDelete(
            $this->context($this->createMock(HttpRequest::class), $messages),
            $filter,
            $factory,
            $repo,
            $canceller
        );
        $controller->execute();

        self::assertSame(['cancel', 'delete 5', 'delete 6', 'delete 7'], $calls);
    }

    private function deleteController(
        TokenRepository $repo,
        JobCanceller $canceller,
        MessageManagerInterface $messages
    ): Delete {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getParam')->with('id')->willReturn('5');
        return new Delete($this->context($request, $messages), $repo, $canceller);
    }

    private function token(int $id, ?string $revokedAt = null, ?int $clientId = null): Token
    {
        $token = $this->createMock(Token::class);
        $token->method('getOAuthClientId')->willReturn($clientId);
        $token->method('getRevokedAt')->willReturn($revokedAt);
        $token->method('getId')->willReturn($id);
        $token->method('getName')->willReturn('t' . $id);
        return $token;
    }

    private function context(HttpRequest $request, MessageManagerInterface $messages): Context
    {
        $redirect = $this->getMockBuilder(Redirect::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setPath'])
            ->getMock();
        $redirect->method('setPath')->willReturnSelf();
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->willReturn($redirect);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultFactory')->willReturn($resultFactory);
        return $context;
    }
}
