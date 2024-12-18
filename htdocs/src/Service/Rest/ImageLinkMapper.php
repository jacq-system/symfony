<?php declare(strict_types=1);

namespace App\Service\Rest;

use App\Entity\Jacq\Herbarinput\Specimens;
use App\Facade\Rest\IiifFacade;
use App\Service\SpecimenService;
use Doctrine\DBAL\Connection;
use Exception;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ImageLinkMapper
{

    protected ?Specimens $specimen = null;
    protected array $imageLinks = array();
    protected array $fileLinks = array();

    public function __construct(protected readonly Connection $connection, protected readonly RouterInterface $router, protected readonly IiifFacade $iiifFacade, protected HttpClientInterface $client, protected readonly SpecimenService $specimenService)
    {
    }

    public function setSpecimen(int $specimenID): static
    {
        $this->specimen = $this->specimenService->findAccessibleForPublic($specimenID);
        $this->linkbuilder();
        return $this;
    }

    private function linkbuilder(): void
    {

        $imageDefinition = $this->specimen->getHerbCollection()->getInstitution()->getImageDefinition();
        if ($this->specimen->hasImage() || $this->specimen->hasImageObservation()) {
            if ($this->specimen->getPhaidraImages() !== null) {
                // for now, special treatment for phaidra is needed when wu has images
                $this->phaidra();
            } elseif ($imageDefinition->isIiifCapable()) {
                $this->iiif();
            } elseif ($imageDefinition->getServerType() === 'bgbm') {
                $this->bgbm();
            } elseif ($imageDefinition->getServerType() == 'djatoka') {
                $this->djatoka();
            }
        }
    }

    /**
     * handle image server type phaidra
     */
    private function phaidra(): void
    {

        $imageDefinition = $this->specimen->getHerbCollection()->getInstitution()->getImageDefinition();
        $iifUrl = $imageDefinition->getIiifUrl();

        $manifestRoute = $this->router->generate('services_rest_iiif_manifest', ['specimenID' => $this->specimen->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->imageLinks[0] = $iifUrl . "?manifest=" . $manifestRoute;
        $manifest = $this->iiifFacade->getManifest($this->specimen);
        if ($manifest) {
            foreach ($manifest['sequences'] as $sequence) {
                foreach ($sequence['canvases'] as $canvas) {
                    foreach ($canvas['images'] as $image) {
                        $this->fileLinks['full'][] = 'https://www.jacq.org/downloadPhaidra.php?filename=' . sprintf("WU%0" . $imageDefinition->getHerbNummerNrDigits() . ".0f", str_replace('-', '', $this->specimen->getHerbNumber())) . ".jpg&url=" . $image['resource']['service']['@id'] . "/full/full/0/default.jpg";
                        $this->fileLinks['europeana'][] = 'https://www.jacq.org/downloadPhaidra.php?filename=' . sprintf("WU%0" . $imageDefinition->getHerbNummerNrDigits() . ".0f", str_replace('-', '', $this->specimen->getHerbNumber())) . ".jpg&url=" . $image['resource']['service']['@id'] . "/full/1200,/0/default.jpg";
                        $this->fileLinks['thumb'][] = 'https://www.jacq.org/downloadPhaidra.php?filename=' . sprintf("WU%0" . $imageDefinition->getHerbNummerNrDigits() . ".0f", str_replace('-', '', $this->specimen->getHerbNumber())) . ".jpg&url=" . $image['resource']['service']['@id'] . "/full/160,/0/default.jpg";
                    }
                }
            }
        }
    }

    /**
     * handle image server type iiif
     */
    private function iiif(): void
    {
        $iifUrl = $this->specimen->getHerbCollection()->getInstitution()->getImageDefinition()->getIiifUrl();
        $this->imageLinks[0] = $iifUrl . "?manifest=" . $this->iiifFacade->resolveManifestUri($this->specimen);
        $manifest = $this->iiifFacade->getManifest($this->specimen);
        if ($manifest) {
            foreach ($manifest['sequences'] as $sequence) {
                foreach ($sequence['canvases'] as $canvas) {
                    foreach ($canvas['images'] as $image) {
                        $this->fileLinks['full'][] = $image['resource']['service']['@id'] . "/full/max/0/default.jpg";
                        $this->fileLinks['europeana'][] = $image['resource']['service']['@id'] . "/full/1200,/0/default.jpg";
                        $this->fileLinks['thumb'][] = $image['resource']['service']['@id'] . "/full/160,/0/default.jpg";
                    }
                }
            }
        }
    }

    /**
     * handle image server type bgbm
     */
    private function bgbm(): void
    {
        $this->imageLinks[0] = 'https://www.jacq.org/image.php?filename=' . rawurlencode(basename((string)$this->specimen->getId())) . "&sid=$this->specimen->getId()&method=show";
        // there is no downloading of a picture
    }

    /**
     * handle image server type djatoka
     */
    private function djatoka(): void
    {

        $imageDefinition = $this->specimen->getHerbCollection()->getInstitution()->getImageDefinition();
        $HerbNummer = str_replace('-', '', $this->specimen->getHerbNumber());

        if (!empty($this->specimen->getHerbCollection()->getPictureFilename())) {   // special treatment for this collection is necessary
            $parts = $this->iiifFacade->parser($this->specimen->getHerbCollection()->getPictureFilename());
            $filename = '';
            foreach ($parts as $part) {
                if ($part['token']) {
                    $tokenParts = explode(':', $part['text']);
                    $token = $tokenParts[0];
                    switch ($token) {
                        case 'coll_short_prj':                                      // use contents of coll_short_prj
                            $filename .= $this->specimen->getHerbCollection()->getCollShortPrj();
                            break;
                        case 'HerbNummer':                                          // use HerbNummer with removed hyphens, options are :num and :reformat
                            if (in_array('num', $tokenParts)) {                     // ignore text with digits within, only use the last number
                                if (preg_match("/\d+$/", $HerbNummer, $matches)) {  // there is a number at the tail of HerbNummer
                                    $number = $matches[0];
                                } else {                                            // HerbNummer ends with text
                                    $number = 0;
                                }
                            } else {
                                $number = $HerbNummer;                              // use the complete HerbNummer
                            }
                            if (in_array("reformat", $tokenParts)) {                // correct the number of digits with leading zeros
                                $filename .= sprintf("%0" . $imageDefinition->getHerbNummerNrDigits() . ".0f", $number);
                            } else {                                                // use it as it is
                                $filename .= $number;
                            }
                            break;
                    }
                } else {
                    $filename .= $part['text'];
                }
            }
        } else {    // standard filename, would be "<coll_short_prj>_<HerbNummer:reformat>"
            $filename = sprintf("%s_%0" . $imageDefinition->getHerbNummerNrDigits() . ".0f", $this->specimen->getHerbCollection()->getCollShortPrj(), $HerbNummer);
        }
        $images = array();
        try {
            //   send requests to jacq-servlet
            $response1 = $this->client->request('POST', $imageDefinition->getImageserverUrl() . 'jacq-servlet/ImageServer', ['json' => ['method' => 'listResources', 'params' => [$imageDefinition->getApiKey(), [$filename, $filename . "_%", $filename . "A", $filename . "B", "tab_" . $this->specimen->getId(), "obs_" . $this->specimen->getId(), "tab_" . $this->specimen->getId() . "_%", "obs_" . $this->specimen->getId() . "_%"]], 'id' => 1], 'verify' => false]);
            $data = json_decode($response1->getContent(), true);
            if (!empty($data['error'])) {
                throw new Exception($data['error']);
            } elseif (empty($data['result'][0])) {
                if ($this->specimen->getHerbCollection()->getInstitution()->getId() == 47) { // FT returns always empty results...
                    throw new Exception("FAIL: '$filename' returned empty result");
                }
            } else {
                foreach ($data['result'] as $pic) {
                    $picProcessed = rawurlencode(basename($pic));
                    if (substr($picProcessed, 0, 4) == 'obs_') {
                        $images_obs[] = $picProcessed;
                    } elseif (substr($picProcessed, 0, 4) == 'tab_') {
                        $images_tab[] = $picProcessed;
                    } else {
                        $images[] = "filename=$picProcessed&sid=" . $this->specimen->getId();
                    }
                }
                if (!empty($images_obs)) {
                    foreach ($images_obs as $pic) {
                        $images[] = "filename=$pic&sid=" . $this->specimen->getId();
                    }
                }
                if (!empty($images_tab)) {
                    foreach ($images_tab as $pic) {
                        $images[] = "filename=$pic&sid=" . $this->specimen->getId();
                    }
                }
            }
        } catch (Exception $e) {
            // something went wrong, so we fall back to the original filename
            $images[0] = 'filename=' . rawurlencode(basename($filename)) . '&sid=' . $this->specimen->getId();
        }

        if (!empty($images)) {
            $firstImage = true;

            foreach ($images as $image) {
                if ($firstImage) {
                    $firstImageFilesize = $this->specimen->getEuropeanaImages()?->getFilesize();
                }
                $this->imageLinks[] = 'https://www.jacq.org/image.php?' . $image . '&method=show';
                $this->fileLinks['full'][] = 'https://www.jacq.org/image.php?' . $image . '&method=download';
                if ($firstImage && ($firstImageFilesize ?? null) > 1500) {  // use europeana-cache only for images without errors and only for the first image
                    $sourceCode = $this->specimen->getHerbCollection()->getInstitution()->getCode();
                    $this->fileLinks['europeana'][] = "https://object.jacq.org/europeana/$sourceCode/$this->specimen->getId().jpg";
                } else {
                    $this->fileLinks['europeana'][] = 'https://www.jacq.org/image.php?' . $image . '&method=europeana';
                }
                $this->fileLinks['thumb'][] = 'https://www.jacq.org/image.php?' . $image . '&method=thumb';
                $firstImage = false;
            }
        }
    }

    public function getShowLink(int $nr = 0): mixed
    {
        return $this->imageLinks[$nr] ?? $this->imageLinks[0] ?? '';
    }

    public function getDownloadLink(int $nr = 0): mixed
    {
        return $this->fileLinks['full'][$nr] ?? $this->fileLinks['full'][0] ?? '';
    }

    public function getEuropeanaLink(int $nr = 0): mixed
    {
        if ($nr === 0) { // only do this, if it's the first (main) image

            if (($this->specimen->getEuropeanaImages()?->getFilesize() ?? null) > 1500) {  // use europeana-cache only for images without errors
                $sourceCode = $this->specimen->getHerbCollection()->getInstitution()->getCode();
                return "https://object.jacq.org/europeana/$sourceCode/$this->specimen->getId().jpg";
            }
        }
        return $this->fileLinks['europeana'][$nr] ?? $this->fileLinks['europeana'][0] ?? '';
    }

    public function getThumbLink(int $nr = 0): mixed
    {
        return $this->fileLinks['thumb'][$nr] ?? $this->fileLinks['thumb'][0] ?? '';
    }

    public function getList(): array
    {
        return array('show' => $this->imageLinks, 'download' => $this->fileLinks);
    }

}
