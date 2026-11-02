<?php

declare(strict_types=1);

namespace Hestia\WebApp\Installers\IaachzPanel;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * iaachz-panel: para los instaladores de "Instalación rápida" que terminan con un paso largo o con
 * argumentos que v-run-cli-cmd no pasa bien (espacios, símbolos). Lanza `v-hp webapp-install` (tarea
 * en segundo plano, como root, que ejecuta el instalador de consola como el usuario) y espera a que
 * termine. Si tarda más de lo que aguanta la página, sigue en segundo plano (Herramientas → Tareas).
 *
 * No es un instalador: la lista de apps solo muestra las clases *Setup.
 */
trait PanelJob {
    protected function runPanelJob(string $app, string $user, string $domain, array $data, int $wait = 540): void {
        $start = $this->vhp(["webapp-install", $app, $user, $domain, base64_encode((string) json_encode($data))]);
        if (empty($start["job"])) {
            throw new RuntimeException($start["error"] ?? "No se pudo iniciar la instalación");
        }

        $deadline = time() + $wait;
        while (time() < $deadline) {
            sleep(3);
            $job = $this->vhp(["job-status", (string) $start["job"], $user]);
            if (($job["status"] ?? "") === "ok") {
                return;
            }
            if (($job["status"] ?? "") === "error") {
                throw new RuntimeException(($job["error"] ?? "La instalación falló") . " (detalle en iaachz-panel → Herramientas → Tareas)");
            }
        }
    }

    private function vhp(array $args): array {
        $process = new Process(["/usr/bin/sudo", "/usr/local/hestia/bin/v-hp", ...$args]);
        $process->setTimeout(120);
        $process->run();

        return json_decode($process->getOutput(), true) ?: ["error" => trim($process->getErrorOutput()) ?: "v-hp no respondió"];
    }
}
