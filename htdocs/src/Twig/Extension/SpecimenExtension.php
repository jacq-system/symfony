<?php declare(strict_types=1);

namespace App\Twig\Extension;

use App\Entity\Jacq\Herbarinput\Specimens;
use App\Facade\Rest\IiifFacade;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class SpecimenExtension extends AbstractExtension
{
    public function __construct(protected readonly IiifFacade $iiifFacade, protected readonly EntityManagerInterface $entityManager)
    {
    }

    public function getFilters()
    {
        return [
            new TwigFilter('manifestUrl', [$this, 'getManifest']),
            new TwigFilter('taxonAuthority', [$this, 'getTaxonAuthority']),
            new TwigFilter('collector', [$this, 'getSpecimenCollector']),
            new TwigFilter('scientificName', [$this, 'getScientificName']),
            new TwigFilter('locality', [$this, 'getLocality']),
            new TwigFilter('typus', [$this, 'getTypus']),
            new TwigFilter('institution', [$this, 'getCollection']),
            new TwigFilter('gps', [$this, 'getGps']),

        ];
    }

    public function getManifest(int $specimenId): string
    {
        return $this->iiifFacade->resolveManifestUri($specimenId);
    }

    public function getTaxonAuthority(int $taxonId): string
    {
        $text = '';
        $sql = "SELECT serviceID, hyper FROM herbar_view.view_taxon_link_service WHERE taxonID = :taxon";
        $result = $this->entityManager->getConnection()->executeQuery($sql, ['taxon' => $taxonId])->fetchAllAssociative();
        if ($result) {
            foreach ($result as $rowtax) {
                $text .= '<br/>';
                if ($rowtax['serviceID'] == 1) {
                    $text .= $rowtax["hyper"] . "&nbsp;";
                    $text .= str_replace("IPNI (K)", "Plants of the World Online / POWO (K)", str_replace("serviceID1_logo", "serviceID49_logo", str_replace("http://ipni.org/ipni/idPlantNameSearch.do?id=", "http://powo.science.kew.org/taxon/urn:lsid:ipni.org:names:", $rowtax["hyper"])));
                } else {
                    $text .= $rowtax["hyper"];
                }
            }
            //TODO do not keep in database whole HTML including path to assets..
            $text = str_replace('assets/images', '/logo/services', $text);
        }
        return $text;
    }

    public function getSpecimenCollector(Specimens $specimen): string
    {
        $text = '';
        $collector = $specimen->getCollector();
        if ($collector !== null) {

            if (!empty($collector->getWikidataId())) {
                $text .= "<a href=\"" . $collector->getWikidataId() . '" title="wikidata" target="_blank" class="leftnavi"><img src="logo/institutions/wikidata.png" alt="wikidata" width="20px"></a>&nbsp;';
            }
            if (!empty($collector->getHuhId())) {
                $text .= "<a href=\"" . $collector->getHuhId() . '" title="Index of Botanists (HUH)" target="_blank" class="leftnavi"><img src="logo/institutions/huh.png" alt="Index of Botanists (HUH)" height="20px"></a>&nbsp;';
            }
            if (!empty($collector->getViafId())) {
                $text .= "<a href=\"" . $collector->getViafId() . '" title="VIAF" target="_blank" class="leftnavi"><img src="logo/institutions/viaf.png" alt="VIAF" width="20px"></a>&nbsp;';
            }
            if (!empty($collector->getOrcidId())) {
                $text .= "<a href=\"" . $collector->getOrcidId() . '" title="ORCID" target="_blank" class="leftnavi"><img src="logo/institutions/orcid.logo.icon.svg" alt="ORCID" width="20px"></a>&nbsp;';
            }

            $sql = "SELECT Bloodhound_ID FROM herbarinput.tbl_collector WHERE Bloodhound_ID like 'h%' AND SammlerID = :collector";
            $bloodhound = $this->entityManager->getConnection()->executeQuery($sql, ['collector' => $collector->getId()])->fetchOne();
            $text .= "<a href='" . $bloodhound . "' target='_blank' title='Bionomia'><img src='logo/institutions/bionomia_logo.png' alt='Bionomia' width='20px'></a>&nbsp;";

            $text .= $collector->getName();

        }


        if (!empty($specimen->getCollector2())) {
            if (strstr($specimen->getCollector2()->getName(), "&") || strstr($specimen->getCollector2()->getName(), "et al.")) {
                $text .= " et al.";
            } else {
                $text .= " & " . $specimen->getCollector2()->getName();
            }
        }

        if (!empty($specimen->getSeriesNumber())) {
            if (!empty($specimen->getNumber())) {
                $text .= " " . $specimen->getNumber();
            }
            if (!empty($specimen->getAltNumber()) && $specimen->getAltNumber() != "s.n.") {
                $text .= " " . $specimen->getAltNumber();
            }
            if (!empty($specimen->getSeries()?->getName())) {
                $text .= " " . $specimen->getSeries()->getName();
            }
            $text .= " " . $specimen->getSeriesNumber();
        } else {
            if (!empty($specimen->getSeries()?->getName())) {
                $text .= " " . $specimen->getSeries()->getName();
            }
            if (!empty($specimen->getNumber())) {
                $text .= " " . $specimen->getNumber();
            }
            if (!empty($specimen->getAltNumber())) {
                $text .= " " . $specimen->getAltNumber();
            }
        }

        return trim($text);
    }

    public function getScientificName(Specimens $specimen): string
    {

        $sql = "SELECT herbar_view.GetScientificName(:species, 0) AS scientificName";
        return $this->entityManager->getConnection()->executeQuery($sql, ['species' => $specimen->getSpecies()->getId()])->fetchOne();

    }

    public function getLocality(Specimens $specimen): string
    {
        $text ='';
        $switch = false;
        if ($specimen?->getCountry()?->getNameEng() !== null) {
            $text.= "<img src='flags/" . strtolower($specimen->getCountry()->getIsoCode()) . ".png'> " . $specimen->getCountry()->getNameEng();
            $switch = true;
        }
        if ($specimen->getProvince()!== null) {
            if ($switch) {
                $text .= ". ";
            }
            $text.=  $specimen->getProvince()->getName();
            $switch = true;
        }
        if (trim($specimen->getLocality())) {
            if ($switch) {
                $text .= ". ";
            }
            if (strlen(trim($specimen->getLocality())) > 200) {
                $text .= substr(trim($specimen->getLocality()), 0, 200) . "...";
            } else {
                $text .= trim($specimen->getLocality());
            }
        }
        return $text;
    }
    public function getTypus(Specimens $specimen): string
    {
        $text ='';
        $sql = "SELECT t.typus
                FROM tbl_specimens_types tst
                 LEFT JOIN tbl_typi t ON t.typusID = tst.typusID
                WHERE tst.specimenID = :specimen";

        $typi = $this->entityManager->getConnection()->executeQuery($sql, ['specimen'=> $specimen->getId()])->fetchFirstColumn();
        $first = true;
        foreach ($typi as $typus) {
            if (!$first) {
                $text.= "<br>";
            }
            $text .= '<span class="red-text"><b>'.$typus . '</b></span>';
            $first = false;
        }

        return $text;
    }
    public function getCollection(Specimens $specimen): string
    {//TODO some smarter way to provide title in <td>
        $text ='';
        if ($specimen->getCollection()->getInstitution()->getId() == '29') {
           $text.= "<td title=\"".$specimen->getCollection()->getName() . "\">";
        $text.=  $specimen->getHerbNumber() ."</td>";
        } else {
            $text.= "<td title='" . $specimen->getCollection()->getName() . "'>"   ;
            $text.= (mb_strtoupper($specimen->getCollection()->getCollShortPrj())) . " " . $specimen->getHerbNumber() . "</td>";
            //. htmlspecialchars(collectionItem($specimen['collection'])) . " " . htmlspecialchars($specimen['HerbNummer']) . "</td>";
        }
        return $text;
    }
    public function getGps(Specimens $specimen): string
    {
        $text ='';
        if ($specimen->getLongitude() != null || $specimen->getLatitude() != null) {
            $text.="<img class='gps' width='15' height='15' src='logo/institutions/OpenStreetMap.png'  data-gps='".round($specimen->getLatitude(),5).",".$specimen->getLongitude()."'>";
        }

        return $text;
    }
}
