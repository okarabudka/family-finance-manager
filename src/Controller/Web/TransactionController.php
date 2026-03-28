<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Domain\Transaction\TransactionService;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Form\TransactionFormType;
use App\Repository\TransactionRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/transactions')]
class TransactionController extends AbstractController
{
    #[Route('', name: 'transaction_list', methods: ['GET'])]
    public function list(
        Request $request,
        TransactionRepository $repo,
        PaginatorInterface $paginator,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $family = $user->getFamily();

        if ($family === null) {
            return $this->redirectToRoute('dashboard');
        }

        $filters = array_filter([
            'type'     => $request->query->get('type'),
            'category' => $request->query->get('category'),
            'from'     => $request->query->get('from'),
            'to'       => $request->query->get('to'),
        ]);

        $query = $repo->createListQuery($family, $filters);
        $transactions = $paginator->paginate($query, $request->query->getInt('page', 1), 30);

        return $this->render('transaction/list.html.twig', [
            'transactions'   => $transactions,
            'filters'        => $filters,
            'transactionTypes' => TransactionType::cases(),
        ]);
    }

    #[Route('/new', name: 'transaction_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        TransactionService $transactionService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $family = $user->getFamily();

        if ($family === null) {
            return $this->redirectToRoute('dashboard');
        }

        $form = $this->createForm(TransactionFormType::class, null, ['family' => $family]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $type = $data['type'];

            /** @var \DateTime|\DateTimeImmutable $executedAtRaw */
            $executedAtRaw = $data['executedAt'];
            $executedAt = $executedAtRaw instanceof \DateTimeImmutable
                ? $executedAtRaw
                : \DateTimeImmutable::createFromMutable($executedAtRaw);

            match ($type) {
                TransactionType::Income => $transactionService->addIncome(
                    family: $family,
                    user: $user,
                    amount: (string) $data['amount'],
                    category: $data['category'],
                    account: $data['account'],
                    description: (string) ($data['description'] ?? ''),
                    date: $executedAt,
                ),
                TransactionType::Transfer => $transactionService->addTransfer(
                    family: $family,
                    user: $user,
                    amount: (string) $data['amount'],
                    from: $data['account'],
                    to: $data['targetAccount'] ?? $data['account'],
                    description: (string) ($data['description'] ?? ''),
                    date: $executedAt,
                ),
                default => $transactionService->addExpense(
                    family: $family,
                    user: $user,
                    amount: (string) $data['amount'],
                    category: $data['category'],
                    account: $data['account'],
                    description: (string) ($data['description'] ?? ''),
                    date: $executedAt,
                ),
            };

            $this->addFlash('success', 'Transaction added successfully.');

            return $this->redirectToRoute('transaction_list');
        }

        return $this->render('transaction/form.html.twig', [
            'form'  => $form,
            'title' => 'New Transaction',
        ]);
    }

    #[Route('/{id}/edit', name: 'transaction_edit', methods: ['GET', 'POST'])]
    public function edit(
        Transaction $transaction,
        Request $request,
        TransactionService $transactionService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $family = $user->getFamily();

        if ($family === null || !$transaction->getFamily()->getId()->equals($family->getId())) {
            throw $this->createAccessDeniedException();
        }

        $initialData = [
            'type'        => $transaction->getType(),
            'amount'      => $transaction->getAmount(),
            'description' => $transaction->getDescription(),
            'category'    => $transaction->getCategory(),
            'account'     => $transaction->getSourceAccount() ?? $transaction->getTargetAccount(),
            'executedAt'  => \DateTime::createFromImmutable($transaction->getExecutedAt()),
        ];

        $form = $this->createForm(TransactionFormType::class, $initialData, ['family' => $family]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            /** @var \DateTime|\DateTimeImmutable $executedAtRaw */
            $executedAtRaw = $data['executedAt'];
            $executedAt = $executedAtRaw instanceof \DateTimeImmutable
                ? $executedAtRaw
                : \DateTimeImmutable::createFromMutable($executedAtRaw);

            $transactionService->update($transaction, [
                'amount'      => (string) $data['amount'],
                'description' => $data['description'],
                'category'    => $data['category'],
                'executedAt'  => $executedAt,
            ]);

            $this->addFlash('success', 'Transaction updated successfully.');

            return $this->redirectToRoute('transaction_list');
        }

        return $this->render('transaction/form.html.twig', [
            'form'        => $form,
            'title'       => 'Edit Transaction',
            'transaction' => $transaction,
        ]);
    }

    #[Route('/{id}/delete', name: 'transaction_delete', methods: ['POST'])]
    public function delete(
        Transaction $transaction,
        Request $request,
        TransactionService $transactionService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $family = $user->getFamily();

        if ($family === null || !$transaction->getFamily()->getId()->equals($family->getId())) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('delete_transaction_' . $transaction->getId(), $request->request->get('_token'))) {
            $transactionService->delete($transaction);
            $this->addFlash('success', 'Transaction deleted.');
        } else {
            $this->addFlash('error', 'Invalid CSRF token.');
        }

        return $this->redirectToRoute('transaction_list');
    }
}
