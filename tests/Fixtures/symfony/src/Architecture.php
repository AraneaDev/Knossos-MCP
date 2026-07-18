<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Attribute\Route;

final class CheckoutRequested {}

interface CheckoutGateway {}

#[AsAlias(id: 'app.checkout_gateway')]
final class StripeGateway implements CheckoutGateway {}

#[AsController]
#[Route('/shop')]
final class CheckoutController extends AbstractController
{
    public function __construct(
        private CheckoutGateway $gateway,
        #[Autowire(service: 'app.audit')] private object $audit,
    ) {}

    #[Route('/checkout', name: 'shop.checkout', methods: ['POST', 'GET'])]
    public function checkout(): void {}

    #[Route(path: self::DYNAMIC_PATH)]
    public function dynamic(): void {}
}

#[AsCommand(name: 'app:reconcile')]
final class ReconcileCommand {}

#[AsMessageHandler]
final class CheckoutHandler
{
    public function __invoke(CheckoutRequested $message): void {}
}

#[AsEventListener(event: RequestEvent::class)]
final class RequestListener {}

final class KernelSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'onRequest'];
    }
}
