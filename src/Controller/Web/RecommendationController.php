<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Entity\Recommendation;
use App\Entity\User;
use App\Repository\RecommendationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/recommendations')]
class RecommendationController extends AbstractController
{
    #[Route('', name: 'recommendation_list', methods: ['GET'])]
    public function list(
        RecommendationRepository $recommendationRepository,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $family = $user->getFamily();

        if (null === $family) {
            return $this->redirectToRoute('dashboard');
        }

        $recommendations = $recommendationRepository->findActiveByFamily($family);

        return $this->render('recommendation/list.html.twig', [
            'recommendations' => $recommendations,
        ]);
    }

    #[Route('/{id}/mark-seen', name: 'recommendation_mark_seen', methods: ['POST'])]
    public function markSeen(
        Recommendation $recommendation,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $family = $user->getFamily();

        if (null === $family || !$recommendation->getFamily()->getId()->equals($family->getId())) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('mark_seen_'.$recommendation->getId(), $request->request->getString('_token'))) {
            $recommendation->setSeenByUser(true);
            $em->flush();
            $this->addFlash('success', 'flash.recommendation.seen');
        } else {
            $this->addFlash('error', 'flash.invalid_csrf');
        }

        return $this->redirectToRoute('recommendation_list');
    }
}
