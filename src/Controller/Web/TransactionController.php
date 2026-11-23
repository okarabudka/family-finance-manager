<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Domain\Transaction\TransactionService;
use App\Entity\Transaction;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Form\Data\TransactionData;
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
            'type'     => $request->query->getString('type'),
            'category' => $request->query->getString('category'),
            'from'     => $request->query->getString('from'),
            'to'       => $request->query->getString('to'),
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

        $data = new TransactionData();
        $form = $this->createForm(TransactionFormType::class, $data, ['family' => $family]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $data->amount && null !== $data->account && null !== $data->executedAt, 'guaranteed by the form constraints');
            $description = $data->description ?? '';

            match ($data->type) {
                TransactionType::Income => $transactionService->addIncome(
                    family: $family,
                    user: $user,
                    amount: $data->amount,
                    category: $data->category,
                    account: $data->account,
                    description: $description,
                    date: $data->executedAt,
                ),
                TransactionType::Transfer => $transactionService->addTransfer(
                    family: $family,
                    user: $user,
                    amount: $data->amount,
                    from: $data->account,
                    to: $data->targetAccount ?? $data->account,
                    description: $description,
                    date: $data->executedAt,
                ),
                default => $transactionService->addExpense(
                    family: $family,
                    user: $user,
                    amount: $data->amount,
                    category: $data->category,
                    account: $data->account,
                    description: $description,
                    date: $data->executedAt,
                ),
            };

            $this->addFlash('success', 'flash.transaction.created');

            return $this->redirectToRoute('transaction_list');
        }

        return $this->render('transaction/form.html.twig', [
            'form'  => $form,
            'title' => 'transaction.new',
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

        $data = TransactionData::fromTransaction($transaction);
        $form = $this->createForm(TransactionFormType::class, $data, ['family' => $family]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            \assert(null !== $data->amount && null !== $data->executedAt, 'guaranteed by the form constraints');

            $transactionService->update($transaction, [
                'amount'      => $data->amount,
                'description' => $data->description,
                'category'    => $data->category,
                'executedAt'  => $data->executedAt,
            ]);

            $this->addFlash('success', 'flash.transaction.updated');

            return $this->redirectToRoute('transaction_list');
        }

        return $this->render('transaction/form.html.twig', [
            'form'        => $form,
            'title'       => 'transaction.edit',
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

        if ($this->isCsrfTokenValid('delete_transaction_' . $transaction->getId(), $request->request->getString('_token'))) {
            $transactionService->delete($transaction);
            $this->addFlash('success', 'flash.transaction.deleted');
        } else {
            $this->addFlash('error', 'flash.invalid_csrf');
        }

        return $this->redirectToRoute('transaction_list');
    }
}
