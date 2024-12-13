<?php declare(strict_types = 1);

namespace App\Entity\Jacq\Herbarinput;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity()]
#[ORM\Table(name: 'tbl_img_definition', schema: 'herbarinput')]
class ImageDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'img_def_ID')]
    private ?int $id = null;

    #[ORM\Column(name: 'iiif_capable')]
    private bool $iiifCapable;


    #[ORM\Column(name: 'iiif_url')]
    private string $iiifUrl;

    #[ORM\Column(name: 'HerbNummerNrDigits')]
    private int $herbNummerNrDigits;


    #[ORM\Column(name: 'imgserver_type')]
    private string $serverType;

    #[ORM\OneToOne(targetEntity: Meta::class, inversedBy: 'imageDefinition')]
    #[ORM\JoinColumn(name: 'source_id_fk', referencedColumnName: 'source_id')]
    private Meta|null $institution = null;

    public function getInstitution(): ?Meta
    {
        return $this->institution;
    }

    public function isIiifCapable(): bool
    {
        return $this->iiifCapable;
    }

    public function getIiifUrl(): string
    {
        return $this->iiifUrl;
    }

    public function getHerbNummerNrDigits(): int
    {
        return $this->herbNummerNrDigits;
    }

    public function getServerType(): string
    {
        return $this->serverType;
    }



}
