<?php

namespace App\Exports;

use App\Models\Boutique;
use App\Support\Plateforme;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export Excel générique : les montants restent des nombres (additionnables) au format GNF.
 * En tête du fichier : logo, nom, coordonnées de la boutique et bandeau à ses couleurs.
 */
class TableauExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomStartCell, WithEvents, WithHeadings, WithTitle
{
    /** Ligne des intitulés de colonnes (les lignes au-dessus forment l'en-tête de l'entreprise). */
    private const LIGNE_ENTETE = 9;

    /**
     * @param  array<string,string>  $colonnes  clé => intitulé
     * @param  array<int,array<string,mixed>>  $lignes
     * @param  string[]  $montants  clés des colonnes en GNF
     * @param  array<string,int>  $totaux  clé => total
     */
    public function __construct(
        private string $titre,
        private array $colonnes,
        private array $lignes,
        private array $montants = [],
        private ?Boutique $boutique = null,
        private ?string $sousTitre = null,
        private array $totaux = [],
    ) {
    }

    public function array(): array
    {
        return array_map(fn ($l) => array_map(fn ($k) => $l[$k] ?? '', array_keys($this->colonnes)), $this->lignes);
    }

    public function headings(): array
    {
        return array_values($this->colonnes);
    }

    public function startCell(): string
    {
        return 'A'.self::LIGNE_ENTETE;
    }

    public function columnFormats(): array
    {
        $formats = [];
        foreach (array_keys($this->colonnes) as $i => $cle) {
            if (in_array($cle, $this->montants, true)) {
                $formats[Coordinate::stringFromColumnIndex($i + 1)] = '#,##0 "GNF"';
            }
        }

        return $formats;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $e) => $this->mettreEnForme($e->sheet->getDelegate())];
    }

    public function title(): string
    {
        return mb_substr($this->titre, 0, 31);
    }

    private function mettreEnForme(Worksheet $f): void
    {
        $b = $this->boutique;
        $derniere = Coordinate::stringFromColumnIndex(max(2, count($this->colonnes)));
        $couleur = ltrim($b?->couleur ?? '#1F6F54', '#');
        $h = self::LIGNE_ENTETE;

        // Lignes 1 à 3 : logo ; 4 : nom ; 5-6 : coordonnées ; 7 : titre ; 8 : bandeau aux couleurs
        $logoChemin = $b?->logoChemin();
        if ($logoChemin) {
            $logo = new Drawing;
            $logo->setName('Logo')->setPath($logoChemin)->setHeight(58)->setCoordinates('A1')->setOffsetX(4)->setOffsetY(4);
            $logo->setWorksheet($f);
        }
        foreach ([1, 2, 3] as $r) {
            $f->getRowDimension($r)->setRowHeight($logoChemin ? 17 : 4);
        }

        // Sans boutique (exports de l'espace Propriétaire) : coordonnées de l'éditeur
        $coordonnees = $b ? $b->coordonnees() : array_values(array_filter([
            Plateforme::get('adresse'), Plateforme::get('telephone'), Plateforme::get('email'),
        ]));
        $textes = [
            4 => $b?->nom ?? Plateforme::get('societe', config('app.name')),
            5 => $coordonnees[0] ?? '',
            6 => implode('  ·  ', array_slice($coordonnees, 1)),
            7 => $this->titre.($this->sousTitre ? ' — '.$this->sousTitre : '').'  (édité le '.now()->format('d/m/Y à H:i').')',
        ];
        foreach ($textes as $ligne => $texte) {
            // Cellules fusionnées : n'influencent pas la largeur automatique des colonnes
            $f->mergeCells("A{$ligne}:{$derniere}{$ligne}");
            $f->setCellValue("A{$ligne}", $texte);
        }
        $f->getStyle('A4')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($couleur);
        $f->getStyle('A5:A6')->getFont()->setSize(9)->getColor()->setRGB('5E6B65');
        $f->getStyle('A7')->getFont()->setBold(true)->setSize(12);

        // Bandeau : les couleurs de l'entreprise se partagent la largeur du tableau
        $couleurs = $b ? $b->couleurs() : ['#1F6F54'];
        $nb = max(2, count($this->colonnes));
        for ($i = 1; $i <= $nb; $i++) {
            $c = $couleurs[(int) floor(($i - 1) * count($couleurs) / $nb)];
            $f->getStyle(Coordinate::stringFromColumnIndex($i).'8')->getFill()->setFillType('solid')->getStartColor()->setRGB(ltrim($c, '#'));
        }
        $f->getRowDimension(8)->setRowHeight(5);

        // Intitulés de colonnes à la couleur principale
        $f->getStyle("A{$h}:{$derniere}{$h}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => ltrim(Boutique::texteSur('#'.$couleur), '#')]],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $couleur]],
        ]);
        $f->freezePane('A'.($h + 1));

        // Ligne de total
        if ($this->totaux) {
            $ligne = $h + count($this->lignes) + 1;
            foreach (array_keys($this->colonnes) as $i => $cle) {
                $cellule = Coordinate::stringFromColumnIndex($i + 1).$ligne;
                $f->setCellValue($cellule, $i === 0 ? 'Total' : ($this->totaux[$cle] ?? null));
            }
            $f->getStyle("A{$ligne}:{$derniere}{$ligne}")->applyFromArray([
                'font' => ['bold' => true],
                'borders' => ['top' => ['borderStyle' => 'medium', 'color' => ['rgb' => ltrim($b?->couleurAccent() ?? '#1C2622', '#')]]],
            ]);
        }

        $f->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        $f->getHeaderFooter()->setOddFooter('&L'.str_replace('&', '&&', $b?->nom ?? config('app.name')).'&RPage &P / &N');
    }
}
