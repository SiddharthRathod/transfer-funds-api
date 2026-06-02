<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\TransferRequest;
use App\Exception\AccountNotFoundException;
use App\Exception\ConcurrentTransferException;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidTransferException;
use App\Service\TransferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
final class TransferController extends AbstractController
{
    public function __construct(
        private readonly TransferService $transferService,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('/transfers', name: 'api_transfer_create', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->error('Invalid JSON body.', Response::HTTP_BAD_REQUEST);
        }

        $dto = new TransferRequest(
            fromAccountId: $data['fromAccountId'] ?? null,
            toAccountId:   $data['toAccountId'] ?? null,
            amount:        $data['amount'] ?? null,
        );

        $violations = $this->validator->validate($dto);

        if (count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = $violation->getMessage();
            }

            return $this->error('Validation failed.', Response::HTTP_BAD_REQUEST, $messages);
        }

        try {
            $transfer = $this->transferService->transfer(
                (int) $dto->fromAccountId,
                (int) $dto->toAccountId,
                (string) $dto->amount,
            );
        } catch (ConcurrentTransferException) {
            return $this->error(
                'Another transfer is already in progress for this account',
                Response::HTTP_CONFLICT,
            );
        } catch (AccountNotFoundException $e) {
            return $this->error($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (InsufficientFundsException $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InvalidTransferException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable) {
            return $this->error('Transfer failed.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'success'   => true,
            'reference' => $transfer->getReference(),
            'status'    => $transfer->getStatus(),
        ], Response::HTTP_CREATED);
    }

    private function error(string $message, int $status, array $errors = []): JsonResponse
    {
        $body = ['success' => false, 'message' => $message];

        if (!empty($errors)) {
            $body['errors'] = $errors;
        }

        return $this->json($body, $status);
    }
}
