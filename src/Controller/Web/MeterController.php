<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Domain\Meter\MeterService;
use App\Entity\MeterReading;
use App\Entity\MeterType;
use App\Entity\User;
use App\Repository\MeterReadingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/meters')]
class MeterController extends AbstractController
{
    #[Route('', name: 'meter_list', methods: ['GET'])]
    public function list(
        MeterReadingRepository $meterReadingRepository,
        MeterService $meterService,
    ): Response {
        /** @var User $user */
        $user   = $this->getUser();
        $family = $user->getFamily();

        if ($family === null) {
            return $this->redirectToRoute('dashboard');
        }

        $electricityReadings = $meterService->getReadingsWithConsumption($family, MeterType::Electricity);
        $waterReadings       = $meterService->getReadingsWithConsumption($family, MeterType::Water);
        $electricityChart    = $meterService->getChartData($family, MeterType::Electricity);
        $waterChart          = $meterService->getChartData($family, MeterType::Water);

        return $this->render('meter/list.html.twig', [
            'electricityReadings' => $electricityReadings,
            'waterReadings'       => $waterReadings,
            'electricityChart'    => $electricityChart,
            'waterChart'          => $waterChart,
        ]);
    }

    #[Route('/photo/{id}', name: 'meter_photo', methods: ['GET'])]
    public function photo(MeterReading $reading): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->getFamily() === null || !$reading->getFamily()->getId()->equals($user->getFamily()->getId())) {
            throw $this->createAccessDeniedException();
        }

        $path = $reading->getPhotoPath();

        if ($path === null || !file_exists($path)) {
            throw $this->createNotFoundException('Photo not found.');
        }

        $mimeType = MimeTypes::getDefault()->guessMimeType($path) ?? 'application/octet-stream';

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);
        $response->headers->set('Content-Type', $mimeType);

        return $response;
    }

    #[Route('/{id}/delete', name: 'meter_delete', methods: ['POST'])]
    public function delete(MeterReading $reading, EntityManagerInterface $em, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->getFamily() === null || !$reading->getFamily()->getId()->equals($user->getFamily()->getId())) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('delete_meter_' . $reading->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $em->remove($reading);
        $em->flush();

        $this->addFlash('success', 'flash.meter.deleted');

        return $this->redirectToRoute('meter_list');
    }
}
