<?php declare(strict_types=1);

namespace App\Controller\Front;

use App\Entity\User;
use App\Enum\CoreObjectsEnum;
use App\Enum\TimeIntervalEnum;
use App\Facade\SearchFormFacade;
use App\Service\CollectionService;
use App\Service\DjatokaService;
use App\Service\ExcelService;
use App\Service\ImageService;
use App\Service\InstitutionService;
use App\Service\KmlService;
use App\Service\Rest\DevelopersService;
use App\Service\Rest\StatisticsService;
use App\Service\SearchFormSessionService;
use App\Service\SpecimenService;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Ods;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

class SearchFormController extends AbstractController
{

    public const array RECORDS_PER_PAGE = array(10, 30, 50, 100);

    //TODO the name of taxon is not part of the query now, hard to sort
    public const array SORT = ["taxon"=> '', 'collector'=>'s.collector'];

    public function __construct( protected readonly CollectionService $collectionService, protected readonly InstitutionService $herbariumService, protected readonly SearchFormFacade $searchFormFacade, protected readonly SearchFormSessionService $sessionService, protected readonly ImageService $imageService, protected readonly SpecimenService $specimenService, protected readonly ExcelService $excelService)
    {
    }

    #[Route('/database', name: 'app_front_database')]
    public function database(Request $request, #[MapQueryParameter] bool $reset = false): Response
    {
        if ($reset) {
            $this->sessionService->reset();
            return $this->redirectToRoute('app_front_database');
        }
        $getData = $request->query->all();
        if (!empty($getData)) {
            $this->sessionService->setFilters($getData);
        }

        $institutions = $this->herbariumService->getAllPairsCodeName();
        $collections = $this->collectionService->getAllAsPairs();
        return $this->render('front/home/database.html.twig', ["institutions" => $institutions, 'collections' => $collections, 'sessionService' => $this->sessionService]);
    }

    #[Route('/databaseSearch', name: 'app_front_databaseSearch', methods: ['POST'])]
    public function databaseSearch(Request $request): Response
    {
        $postData = $request->request->all();
        $this->sessionService->setFilters($postData);

        $pagination = $this->searchFormFacade->providePaginationInfo();

        return $this->render('front/home/databaseSearch.html.twig', [
            'records' => $this->searchFormFacade->search(),
            'recordsCount' => $pagination["totalRecords"],
            'totalPages' => $pagination['totalPages'],
            'pages' => $pagination['pages'],
            'recordsPerPage' => self::RECORDS_PER_PAGE,
            'sessionService' => $this->sessionService]);
    }

    #[Route('/databaseSearchSettings', name: 'app_front_databaseSearchSettings', methods: ['GET'])]
    public function databaseSearchSettings(#[MapQueryParameter] string $feature, #[MapQueryParameter] string $value): Response
    {
        switch ($feature) {
            case "page":
                $this->sessionService->setSetting('page', $value);
                break;
            case "recordsPerPage":
                $this->sessionService->setSetting('recordsPerPage', $value);
                break;
            case "sort":
                $this->sessionService->setSetting('sort', $value);
                break;
            default:
                break;
        }
        return new Response();
    }

    #[Route('/collectionsSelectOptions', name: 'app_front_collectionsSelectOptions', methods: ['GET'])]
    public function collectionsSelectOptions(#[MapQueryParameter] string $herbariumID): Response
    {
        $result = $this->collectionService->getAllFromHerbariumAsPairsByAbbrev($herbariumID);

        return new JsonResponse($result);
    }

    #[Route('/image', name: 'app_front_image_endpoint', methods: ['GET'])]
    public function showImage(#[MapQueryParameter] string $filename,#[MapQueryParameter] ?string $sid,#[MapQueryParameter] string $method,#[MapQueryParameter] ?string $format): Response
    {
        if ($_SERVER['REMOTE_ADDR'] == '94.177.9.139' && !empty($sid) && $method == 'download' && strrpos($filename, '_') == strpos($filename, '_')) {
            // kulturpool is calling...
            // Redirect to new location
            $this->redirectToRoute("services_rest_images_europeana", ["specimenID"=>$sid], 303);
        }

        switch ($format) {
            case 'jpeg2000':
                $mime = 'image/jp2';
                break;
            case'tiff':
                $mime = 'image/tiff';
                break;
            default:
                $mime = 'image/jpeg';
                break;
        }

        $picDetails = $this->imageService->getPicDetails($filename,$mime, $sid);

        if (!empty($picDetails['url'])) {
            switch ($method) {
                default:
                    $this->imageService->getSourceUrl($picDetails,$mime, 0);
                    exit;
                case 'download':    // detail

                    return new StreamedResponse(function () use ($picDetails, $mime) {
                        // ignore broken certificates
                        $context = stream_context_create(array("ssl"=>array("verify_peer"      => false,
                            "verify_peer_name" => false),
                            "http"=>array('timeout' => 60)));
                        readfile($url, false, $context);
                        $this->imageService->getSourceUrl($picDetails,$mime, 0);
                    });
                case 'thumb':       // detail
                            return new JsonResponse(['dd' => $this->imageService->getSourceUrl($picDetails,$mime, 1)], 202);

                return new StreamedResponse(function () use ($picDetails, $mime) {
                        $this->imageService->getSourceUrl($picDetails,$mime, 1);
                    });
                case 'resized':     // create_xml.php
                    $this->imageService->getSourceUrl($picDetails,$mime, 2);
                    exit;
                case 'europeana':   // NOTE: not supported on non-djatoka servers (yet)
                    if (strtolower(substr($picDetails['requestFileName'], 0, 3)) == 'wu_' && $this->imageService->checkPhaidra((int)$picDetails['specimenID'])) {
                        // Phaidra (only WU)
                        $picDetails['imgserver_type'] = 'phaidra';
                    } else {
                        // Djatoka
                        $picinfo = $this->imageService->getPicInfo($picDetails);
                        if (!empty($picinfo['pics'][0]) && !in_array($picDetails['originalFilename'], $picinfo['pics']))  {
                            $picDetails['originalFilename'] = $picinfo['pics'][0];
                        }
                    }
                    $this->imageService->getSourceUrl($picDetails,$mime, 3);
                    exit;
                case 'nhmwthumb':   // NOTE: not supported on legacy image server scripts
                    $this->imageService->getSourceUrl($picDetails,$mime, 4);
                    exit;
                case 'thumbs':      // unused
                    return $this->json($this->imageService->getPicInfo($picDetails));
                case 'show':        // detail, ajax/results.php
                    return $this->redirect($this->imageService->doRedirectShowPic($picDetails));
            }

        } else {
            switch ($method) {
                case 'download':
                case 'thumb':
                $filePath = $this->getParameter('kernel.project_dir') . '/public/recordIcons/404.png';

                if (!file_exists($filePath) || mime_content_type($filePath) !== 'image/png') {
                    throw $this->createNotFoundException('Sorry, this image does not exist.');
                }

                return new Response(file_get_contents($filePath), 200, [
                    'Content-Type' => 'image/png',
                    'Content-Length' => filesize($filePath),
                ]);
                case 'thumbs':
                    return new JsonResponse(['error' => 'not found'], 404);
                default:
                    return new Response('not found', 404);

            }
        }
    }

    #[Route('/detail/{specimenId}', name: 'app_front_specimenDetail', methods: ['GET'])]
    public function detail(int $specimenId): Response
    {
        return $this->render('front/home/detail.html.twig', ['specimen'=> $this->specimenService->findAccessibleForPublic($specimenId)]);
    }

    #[Route('/exportKml', name: 'app_front_exportKml', methods: ['GET'])]
    public function exportKml(): Response
    {
        $kmlContent = $this->searchFormFacade->getKmlExport();
        $response = new Response($kmlContent);
        $response->headers->set('Content-Type', 'application/vnd.google-earth.kml+xml');
        $response->headers->set('Content-Disposition', 'attachment; filename="specimens_download.kml"');

        return $response;
    }
    #[Route('/exportExcel', name: 'app_front_exportExcel', methods: ['GET'])]
    public function exportExcel(): Response
    {
        $spreadsheet = $this->excelService->prepareExcel();
        $filledSpreadsheet = $this->excelService->easyFillExcel($spreadsheet, ExcelService::HEADER, $this->searchFormFacade->getSpecimenDataforExport());

        $response = new StreamedResponse(function () use ($filledSpreadsheet) {
            $writer = new Xlsx($filledSpreadsheet);
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment;filename="specimens_download.xlsx"');
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }
    #[Route('/exportCsv', name: 'app_front_exportCsv', methods: ['GET'])]
    public function exportCsv(): Response
    {
        $spreadsheet = $this->excelService->prepareExcel();
        $filledSpreadsheet = $this->excelService->easyFillExcel($spreadsheet, ExcelService::HEADER, $this->searchFormFacade->getSpecimenDataforExport());

        $response = new StreamedResponse(function () use ($filledSpreadsheet) {
            $writer = new Csv($filledSpreadsheet);
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment;filename="specimens_download.csv"');
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }
    #[Route('/exportOds', name: 'app_front_exportOds', methods: ['GET'])]
    public function exportOds(): Response
    {
        $spreadsheet = $this->excelService->prepareExcel();
        $filledSpreadsheet = $this->excelService->easyFillExcel($spreadsheet, ExcelService::HEADER, $this->searchFormFacade->getSpecimenDataforExport());

        $response = new StreamedResponse(function () use ($filledSpreadsheet) {
            $writer = new Ods($filledSpreadsheet);
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.oasis.opendocument.spreadsheet');
        $response->headers->set('Content-Disposition', 'attachment;filename="specimens_download.ods"');
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }

}
