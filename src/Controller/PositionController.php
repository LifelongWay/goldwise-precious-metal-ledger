<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\LedgerService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class PositionController
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    #[Route('/users/{userId}/positions', name: 'positions_list', requirements: ['userId' => '\d+'], methods: ['GET'])]
    public function list(int $userId): JsonResponse
    {
        return new JsonResponse($this->ledger->getPositions($userId));
    }
}
