<?php

declare(strict_types=1);

namespace Hestia\WebApp\Installers\phpBB;

use Hestia\WebApp\BaseSetup;
use Hestia\WebApp\InstallationTarget\InstallationTarget;
use Hestia\WebApp\Installers\IaachzPanel\PanelJob;
use RuntimeException;

/**
 * iaachz-panel: phpBB (foros). Hestia descarga el código y crea la base de datos; el instalador de
 * consola de phpBB (install/phpbbcli.php) corre como tarea de iaachz-panel, que luego borra install/.
 * phpBB se instala en inglés; el paquete de idioma español se añade desde su panel.
 */
class phpBBSetup extends BaseSetup {
    use PanelJob;

    private const VERSION = "3.3.19";

    protected array $info = [
        "name" => "phpBB",
        "group" => "forum",
        "version" => self::VERSION,
        "thumbnail" => "phpbb-logo.svg",
    ];

    protected array $config = [
        "form" => [
            "board_name" => ["label" => "Nombre del foro", "value" => "Foro"],
            "board_description" => ["label" => "Descripción", "value" => ""],
            "admin_username" => ["label" => "Usuario administrador", "value" => "admin"],
            "admin_password" => ["type" => "password", "label" => "Contraseña del administrador"],
            "admin_email" => ["label" => "Correo del administrador", "value" => ""],
        ],
        "database" => true,
        "resources" => [
            "archive" => ["src" => "https://download.phpbb.com/pub/release/3.3/" . self::VERSION . "/phpBB-" . self::VERSION . ".zip"],
        ],
        "server" => [
            "nginx" => [
                "template" => "phpbb",
            ],
            "php" => [
                "supported" => ["8.1", "8.2", "8.3"],
            ],
        ],
    ];

    public function install(InstallationTarget $target, array $options): void {
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,20}$/', (string) ($options["admin_username"] ?? ""))) {
            throw new RuntimeException("Usuario administrador no válido: de 3 a 20 letras, números o . _ - @");
        }
        if (strlen((string) ($options["admin_password"] ?? "")) < 8) {
            throw new RuntimeException("La contraseña del administrador debe tener al menos 8 caracteres");
        }
        if (!filter_var((string) ($options["admin_email"] ?? ""), FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Escribe un correo válido para el administrador");
        }
        if (trim((string) ($options["board_name"] ?? "")) === "") {
            throw new RuntimeException("Escribe el nombre del foro");
        }
        parent::install($target, $options);
    }

    protected function setupApplication(InstallationTarget $target, array $options): void {
        if (in_array("phpBB3", $this->appcontext->listFiles($target->getDocRoot()), true)) {
            $this->appcontext->copyDirectory($target->getDocRoot("phpBB3/."), $target->getDocRoot());
            $this->appcontext->deleteDirectory($target->getDocRoot("phpBB3"));
        }

        $db = $target->database;
        $this->runPanelJob("phpbb", $this->appcontext->user(), $target->domain->domainName, [
            "php" => $options["php_version"],
            "adminuser" => $options["admin_username"],
            "adminpass" => $options["admin_password"],
            "adminemail" => $options["admin_email"],
            "board_name" => $options["board_name"],
            "board_description" => $options["board_description"] ?? "",
            "dbhost" => $db->host,
            "dbname" => $db->name,
            "dbuser" => $db->user,
            "dbpass" => $db->password,
            "https" => $target->domain->isSslEnabled,
        ]);
    }
}
