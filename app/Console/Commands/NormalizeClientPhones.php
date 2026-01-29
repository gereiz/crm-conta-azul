<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use Illuminate\Console\Command;

class NormalizeClientPhones extends Command
{
    protected $signature = 'clients:normalize-phones';

    protected $description = 'Normaliza telefones dos clientes (apenas dígitos, sem símbolos).';

    public function handle()
    {
        $updatedPhone = 0;
        $updatedMobile = 0;
        $processed = 0;

        Cliente::chunk(500, function ($clientes) use (&$updatedPhone, &$updatedMobile, &$processed) {
            foreach ($clientes as $c) {
                $processed++;
                $origPhone = $c->getOriginal('phone');
                $origMobile = $c->getOriginal('mobile_phone');
                $newPhone = $origPhone !== null ? preg_replace('/\D/', '', (string) $origPhone) : null;
                $newMobile = $origMobile !== null ? preg_replace('/\D/', '', (string) $origMobile) : null;

                $changed = false;
                if ($newPhone !== $origPhone) {
                    $c->phone = $newPhone;
                    $updatedPhone++;
                    $changed = true;
                }
                if ($newMobile !== $origMobile) {
                    $c->mobile_phone = $newMobile;
                    $updatedMobile++;
                    $changed = true;
                }
                if ($changed) {
                    $c->save();
                }
            }
        });

        $this->info("Clientes processados: {$processed}");
        $this->info("Telefones atualizados: {$updatedPhone}");
        $this->info("Celulares atualizados: {$updatedMobile}");

        return Command::SUCCESS;
    }
}
