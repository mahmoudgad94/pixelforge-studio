<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the app's own tables from one database to another with their ids kept (for example a
 * local SQLite file into a hosted Postgres), so history and the report match the provider's usage.
 *
 *   php artisan data:transfer export > data.json      (on the source)
 *   php artisan data:transfer import < data.json      (on the target; replaces these tables)
 *
 * Skipped on purpose: OAuth tables, sessions, cache, jobs.
 */
class TransferData extends Command
{
    protected $signature = 'data:transfer {mode : export or import}';

    protected $description = 'Export app data as JSON, or import it (ids kept, sequences reset)';

    /** Parents first, so foreign keys are satisfied on import. */
    private const TABLES = ['users', 'generations', 'credit_transactions', 'webhook_events', 'admin_actions', 'pixelforge_activity'];

    public function handle(): int
    {
        return match ($this->argument('mode')) {
            'export' => $this->export(),
            'import' => $this->import(),
            default => $this->fail('Mode must be export or import.'),
        };
    }

    private function export(): int
    {
        $data = [];
        foreach (self::TABLES as $table) {
            $data[$table] = Schema::hasTable($table) ? DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all() : [];
        }
        $this->output->write(json_encode($data, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function import(): int
    {
        $data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $driver = DB::connection()->getDriverName();

        DB::transaction(function () use ($data, $driver) {
            foreach (array_reverse(self::TABLES) as $table) {
                DB::table($table)->delete();
            }
            foreach (self::TABLES as $table) {
                $columns = Schema::getColumnListing($table);
                $types = collect(Schema::getColumns($table))->pluck('type_name', 'name');
                foreach (array_chunk($data[$table] ?? [], 200) as $chunk) {
                    DB::table($table)->insert(array_map(function (array $row) use ($columns, $types, $driver) {
                        $row = array_intersect_key($row, array_flip($columns));
                        if ($driver === 'pgsql') {
                            foreach ($row as $col => $value) {
                                if (($types[$col] ?? '') === 'bool' && $value !== null) {
                                    $row[$col] = (bool) $value;
                                }
                            }
                        }

                        return $row;
                    }, $chunk));
                }
                if ($driver === 'pgsql' && in_array('id', $columns, true)) {
                    DB::statement("select setval(pg_get_serial_sequence('{$table}', 'id'), coalesce((select max(id) from {$table}), 1), (select count(*) > 0 from {$table}))");
                }
                $this->line(sprintf('%-22s %d rows', $table, count($data[$table] ?? [])));
            }
        });

        return self::SUCCESS;
    }
}
