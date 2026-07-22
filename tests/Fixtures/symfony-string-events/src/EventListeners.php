<?php

namespace App;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

// AsEventListener with a string event name (not Class::class).
// This exercises the diagnostic branch in SymfonyFactCollector::enterClass()
// when classArgument() returns null for a non-Class::class argument.
#[AsEventListener(event: 'kernel.request')]
final class StringEventListener {}

// EventSubscriber with string key in getSubscribedEvents() return array.
// This exercises the Scalar\String_ branch in SymfonyFactCollector::eventReference().
final class StringEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.response' => 'onResponse',
        ];
    }

    public function onResponse(): void {}
}
