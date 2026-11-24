<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\Mcp\Test\Unit\Controller\Adminhtml\Token;

use Magebit\Mcp\Controller\Adminhtml\Token\MassRevoke;
use Magebit\Mcp\Controller\Adminhtml\Token\Revoke;
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
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;

class RevokeTest extends TestCase
{
    public function testRevokeControllerCancelsJobs(): void
    {
        $token = $this->token(5);
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->with(5)->willReturn($token);
        $repo->expects(self::once())->method('revoke')->with(5)->willReturn($token);
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::once())->method('cancelForRevokedToken')->with($token)->willReturn(2);

        $request = $this->createMock(HttpRequest::class);
        $request->method('getParam')->with('id')->willReturn('5');
        $messages = $this->createMock(MessageManagerInterface::class);
        $messages->expects(self::once())->method('addSuccessMessage')
            ->with(self::stringContains('2 background job(s) were cancelled.'));

        $controller = new Revoke($this->context($request, $messages), $repo, $canceller);
        $controller->execute();
    }

    public function testMassRevokeCancelsJobs(): void
    {
        $first = $this->token(5);
        $second = $this->token(6);
        $collection = $this->createMock(Collection::class);
        $collection->method('getItems')->willReturn([$first, $second]);
        $filter = $this->createMock(Filter::class);
        $filter->method('getCollection')->willReturn($collection);
        $factory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($collection);

        $repo = $this->createMock(TokenRepository::class);
        $calls = [];
        $repo->expects(self::exactly(2))->method('revoke')->willReturnCallback(
            function (int $id) use (&$calls, $first, $second): Token {
                $calls[] = 'revoke ' . $id;
                return $id === 5 ? $first : $second;
            }
        );
        $seen = [];
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::never())->method('cancelForRevokedToken');
        // PR #48 review round 5 (S2): one batched call for the whole selection, after the revokes.
        $canceller->expects(self::once())->method('cancelForConnections')
            ->willReturnCallback(function (array $connections) use (&$seen, &$calls): int {
                $seen = $connections;
                $calls[] = 'cancel';
                return 2;
            });

        $messages = $this->createMock(MessageManagerInterface::class);
        $messages->expects(self::once())->method('addSuccessMessage')
            ->with(self::stringContains('2 background job(s) were cancelled.'));

        $request = $this->createMock(HttpRequest::class);
        $controller = new MassRevoke($this->context($request, $messages), $filter, $factory, $repo, $canceller);
        $controller->execute();

        self::assertEquals([JobConnection::personal(5), JobConnection::personal(6)], $seen);
        self::assertSame(['revoke 5', 'revoke 6', 'cancel'], $calls);
    }

    public function testRevokeAlreadyRevokedTokenDoesNotCancelJobs(): void
    {
        $token = $this->token(5, '2026-11-01 00:00:00');
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->willReturn($token);
        $repo->method('revoke')->willReturn($token);
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::never())->method('cancelForRevokedToken');
        $messages = $this->createMock(MessageManagerInterface::class);
        $messages->expects(self::once())->method('addSuccessMessage')
            ->with('Connection "t5" revoked.');

        $this->revokeController($repo, $canceller, $messages)->execute();
    }

    public function testRevokeNotFoundDoesNotCancelJobs(): void
    {
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->willThrowException(NoSuchEntityException::singleField('id', 5));
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::never())->method('cancelForRevokedToken');
        $messages = $this->createMock(MessageManagerInterface::class);
        $messages->expects(self::once())->method('addErrorMessage');

        $this->revokeController($repo, $canceller, $messages)->execute();
    }

    public function testRevokeFailureDoesNotCancelJobs(): void
    {
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->willReturn($this->token(5));
        $repo->method('revoke')->willThrowException(new \RuntimeException('db'));
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::never())->method('cancelForRevokedToken');
        $messages = $this->createMock(MessageManagerInterface::class);
        $messages->expects(self::once())->method('addErrorMessage');

        $this->revokeController($repo, $canceller, $messages)->execute();
    }

    public function testRevokeMessageUnchangedWhenNothingCancelled(): void
    {
        $token = $this->token(5);
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('getById')->willReturn($token);
        $repo->method('revoke')->willReturn($token);
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::once())->method('cancelForRevokedToken')->willReturn(0);
        $messages = $this->createMock(MessageManagerInterface::class);
        $messages->expects(self::once())->method('addSuccessMessage')->with('Connection "t5" revoked.');

        $this->revokeController($repo, $canceller, $messages)->execute();
    }

    public function testMassRevokeSkipsAlreadyRevokedAndFailedTokens(): void
    {
        $active = $this->token(5);
        $already = $this->token(6, '2026-11-01 00:00:00');
        $failing = $this->token(7);
        $collection = $this->createMock(Collection::class);
        $collection->method('getItems')->willReturn([$active, $already, $failing]);
        $filter = $this->createMock(Filter::class);
        $filter->method('getCollection')->willReturn($collection);
        $factory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($collection);
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('revoke')->willReturnCallback(function (int $id) use ($active, $already) {
            if ($id === 7) {
                throw new \RuntimeException('db');
            }
            return $id === 5 ? $active : $already;
        });
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::once())->method('cancelForConnections')
            ->with([JobConnection::personal(5)])->willReturn(0);
        $messages = $this->createMock(MessageManagerInterface::class);
        $messages->expects(self::once())->method('addSuccessMessage')->with('Revoked 2 connection(s).');

        $controller = new MassRevoke(
            $this->context($this->createMock(HttpRequest::class), $messages),
            $filter,
            $factory,
            $repo,
            $canceller
        );
        $controller->execute();
    }

    public function testMassRevokeOfOnlyRevokedTokensCancelsNothing(): void
    {
        $already = $this->token(6, '2026-11-01 00:00:00');
        $collection = $this->createMock(Collection::class);
        $collection->method('getItems')->willReturn([$already]);
        $filter = $this->createMock(Filter::class);
        $filter->method('getCollection')->willReturn($collection);
        $factory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturn($collection);
        $repo = $this->createMock(TokenRepository::class);
        $repo->method('revoke')->willReturn($already);
        $canceller = $this->createMock(JobCanceller::class);
        $canceller->expects(self::never())->method('cancelForConnections');
        $messages = $this->createMock(MessageManagerInterface::class);
        $messages->expects(self::once())->method('addSuccessMessage')->with('Revoked 1 connection(s).');

        $controller = new MassRevoke(
            $this->context($this->createMock(HttpRequest::class), $messages),
            $filter,
            $factory,
            $repo,
            $canceller
        );
        $controller->execute();
    }

    private function revokeController(
        TokenRepository $repo,
        JobCanceller $canceller,
        MessageManagerInterface $messages
    ): Revoke {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getParam')->with('id')->willReturn('5');
        return new Revoke($this->context($request, $messages), $repo, $canceller);
    }

    private function token(int $id, ?string $revokedAt = null): Token
    {
        $token = $this->createMock(Token::class);
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
