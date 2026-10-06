<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Sauvegarde complète de la base en SQL compressé (.sql.gz), sans dépendre de mysqldump.
 * Restauration : importer le fichier dans phpMyAdmin (ou « gunzip < fichier | mysql base »).
 * Les fichiers restent dans storage/app/sauvegardes, hors du dossier public.
 */
class Sauvegarde
{
    /** Tables techniques non sauvegardées (recréées vides, sans valeur métier). */
    private const IGNOREES = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    public const MOTIF_FICHIER = '/^sauvegarde-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$/';

    public function dossier(): string
    {
        $dossier = storage_path('app/sauvegardes');
        File::ensureDirectoryExists($dossier);

        return $dossier;
    }

    /** Crée une sauvegarde et renvoie le nom du fichier. */
    public function creer(): string
    {
        $nom = 'sauvegarde-'.now()->format('Y-m-d-His').'.sql.gz';
        $chemin = $this->dossier().DIRECTORY_SEPARATOR.$nom;
        $gz = gzopen($chemin, 'wb6');
        if (! $gz) {
            throw new RuntimeException("Impossible d'écrire la sauvegarde dans {$chemin}.");
        }

        try {
            $pilote = DB::getDriverName();
            $pdo = DB::connection()->getPdo();
            gzwrite($gz, '-- Sauvegarde '.config('app.name').' du '.now()->format('d/m/Y H:i:s')." ({$pilote})\n");
            if ($pilote === 'mysql') {
                gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            }

            foreach ($this->tables($pilote) as $table) {
                gzwrite($gz, "\n-- Table {$table}\n");
                gzwrite($gz, $this->ddl($pilote, $table).";\n");
                if (in_array($table, self::IGNOREES, true)) {
                    continue;
                }
                $lot = [];
                foreach (DB::table($table)->cursor() as $ligne) {
                    $lot[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? $v : $pdo->quote((string) $v)), (array) $ligne)).')';
                    if (count($lot) === 200) {
                        gzwrite($gz, $this->insert($table, $lot));
                        $lot = [];
                    }
                }
                if ($lot) {
                    gzwrite($gz, $this->insert($table, $lot));
                }
            }
            if ($pilote === 'mysql') {
                gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
            }
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($chemin);
            throw $e;
        }
        gzclose($gz);

        return $nom;
    }

    /** @return array<int, array{nom:string, taille:int, date:\Carbon\Carbon}> du plus récent au plus ancien */
    public function lister(): array
    {
        return collect(File::files($this->dossier()))
            ->filter(fn ($f) => preg_match(self::MOTIF_FICHIER, $f->getFilename()))
            ->map(fn ($f) => ['nom' => $f->getFilename(), 'taille' => $f->getSize(), 'date' => \Carbon\Carbon::createFromTimestamp($f->getMTime())])
            ->sortByDesc('nom')->values()->all();
    }

    /** Chemin sûr d'une sauvegarde (le nom est vérifié : aucun accès hors du dossier). */
    public function chemin(string $nom): string
    {
        abort_unless(preg_match(self::MOTIF_FICHIER, $nom) && is_file($c = $this->dossier().DIRECTORY_SEPARATOR.$nom), 404);

        return $c;
    }

    /** Ne garde que les N sauvegardes les plus récentes. */
    public function purger(int $garder): int
    {
        $supprimees = 0;
        foreach (array_slice($this->lister(), $garder) as $f) {
            @unlink($this->dossier().DIRECTORY_SEPARATOR.$f['nom']);
            $supprimees++;
        }

        return $supprimees;
    }

    private function tables(string $pilote): array
    {
        return match ($pilote) {
            'mysql' => array_map(fn ($r) => array_values((array) $r)[0], DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')),
            'sqlite' => array_column(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'name'),
            default => throw new RuntimeException("Sauvegarde non prise en charge pour la base {$pilote}."),
        };
    }

    private function ddl(string $pilote, string $table): string
    {
        if ($pilote === 'mysql') {
            return "DROP TABLE IF EXISTS `{$table}`;\n".((array) DB::selectOne("SHOW CREATE TABLE `{$table}`"))['Create Table'];
        }

        return "DROP TABLE IF EXISTS \"{$table}\";\n".DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->sql;
    }

    private function insert(string $table, array $lignes): string
    {
        $q = DB::getDriverName() === 'mysql' ? "`{$table}`" : "\"{$table}\"";

        return "INSERT INTO {$q} VALUES\n".implode(",\n", $lignes).";\n";
    }
}
