<?php

namespace Configured;

use Symfony\Component\Routing\Attribute\Route;

final class CheckoutController
{
    #[Route('/configured', methods: ['GET'])]
    public function show(): void {}
}
