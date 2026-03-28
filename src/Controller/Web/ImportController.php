<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\ImportTransactionStatus;
use App\Entity\User;
use App\Repository\ImportFileRepository;
use App\Repository\ImportTransactionRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/imports')]
class ImportController extends AbstractController
{
    #[Route('', name: 'import_list', methods: ['GET'])]
    public function list(
        Request $request,
        ImportFileRepository $importFileRepository,
        PaginatorInterface $paginator,
    ): Response {
        /** @var User $user */
        $user   = $this->getUser();
        $family = $user->getFamily();

        if ($family === null) {
            return $this->redirectToRoute('dashboard');
        }

        $query = $importFileRepository->createQueryBuilder('f')
            ->join('f.account', 'a')
            ->where('a.family = :family')
            ->setParameter('family', $family)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery();

        $imports = $paginator->paginate($query, $request->query->getInt('page', 1), 20);

        return $this->render('import/list.html.twig', [
            'imports' => $imports,
        ]);
    }

    #[Route('/pending', name: 'import_pending', methods: ['GET'])]
    public function pending(
        Request $request,
        ImportTransactionRepository $importTransactionRepository,
        PaginatorInterface $paginator,
    ): Response {
        /** @var User $user */
        $user   = $this->getUser();
        $family = $user->getFamily();

        if ($family === null) {
            return $this->redirectToRoute('dashboard');
        }

        $query = $importTransactionRepository->createQueryBuilder('it')
            ->join('it.importFile', 'f')
            ->join('f.account', 'a')
            ->where('a.family = :family')
            ->andWhere('it.status = :status')
            ->setParameter('family', $family)
            ->setParameter('status', ImportTransactionStatus::Pending)
            ->orderBy('it.date', 'DESC')
            ->getQuery();

        $transactions = $paginator->paginate($query, $request->query->getInt('page', 1), 30);

        return $this->render('import/pending.html.twig', [
            'transactions' => $transactions,
        ]);
    }
}
