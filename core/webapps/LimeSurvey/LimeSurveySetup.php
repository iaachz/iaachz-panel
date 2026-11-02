<?php

declare(strict_types=1);

namespace Hestia\WebApp\Installers\LimeSurvey;

use Hestia\WebApp\BaseSetup;
use Hestia\WebApp\InstallationTarget\InstallationTarget;
use Hestia\WebApp\Installers\IaachzPanel\PanelJob;
use RuntimeException;

/**
 * iaachz-panel: LimeSurvey (encuestas). Hestia descarga el código y crea la base de datos; el
 * instalador de consola de LimeSurvey (tablas + administrador) corre como tarea de iaachz-panel.
 */
class LimeSurveySetup extends BaseSetup {
    use PanelJob;

    private const VERSION = "7.8.0";
    private const TAG = "7.8.0+261026";

    protected array $info = [
        "name" => "LimeSurvey",
        "group" => "survey",
        "version" => self::VERSION,
        "thumbnail" => "limesurvey-logo.svg",
    ];

    protected array $config = [
        "form" => [
            "admin_username" => ["label" => "Usuario administrador", "value" => "admin"],
            "admin_password" => ["type" => "password", "label" => "Contraseña del administrador"],
            "admin_name" => ["label" => "Nombre del administrador", "value" => "Administrador"],
            "admin_email" => ["label" => "Correo del administrador", "value" => ""],
            "language" => [
                "type" => "select",
                "label" => "Idioma",
                "value" => "es",
                "options" => [
                    "es" => "Español",
                    "es-MX" => "Español (México)",
                    "en" => "English",
                    "pt-BR" => "Português (Brasil)",
                    "fr" => "Français",
                ],
            ],
        ],
        "database" => true,
        "resources" => [
            "archive" => ["src" => "https://github.com/LimeSurvey/LimeSurvey/archive/refs/tags/" . self::TAG . ".zip"],
        ],
        "server" => [
            "nginx" => [
                "template" => "default",
            ],
            "php" => [
                "supported" => ["8.1", "8.2", "8.3", "8.4"],
            ],
        ],
    ];

    public function install(InstallationTarget $target, array $options): void {
        if (!preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', (string) ($options["admin_username"] ?? ""))) {
            throw new RuntimeException("Usuario administrador no válido: usa letras, números y . _ - @");
        }
        if (strlen((string) ($options["admin_password"] ?? "")) < 8) {
            throw new RuntimeException("La contraseña del administrador debe tener al menos 8 caracteres");
        }
        if (!filter_var((string) ($options["admin_email"] ?? ""), FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Escribe un correo válido para el administrador");
        }
        if (!isset($this->config["form"]["language"]["options"][$options["language"] ?? ""])) {
            throw new RuntimeException("Idioma no válido");
        }
        parent::install($target, $options);
    }

    protected function setupApplication(InstallationTarget $target, array $options): void {
        // El zip de GitHub trae todo dentro de LimeSurvey-<versión>/
        foreach ($this->appcontext->listFiles($target->getDocRoot()) as $entry) {
            if (str_starts_with($entry, "LimeSurvey-")) {
                $this->appcontext->copyDirectory($target->getDocRoot($entry . "/."), $target->getDocRoot());
                $this->appcontext->deleteDirectory($target->getDocRoot($entry));
            }
        }

        $db = $target->database;
        $q = fn(string $s): string => var_export($s, true);
        $this->appcontext->createFile(
            $target->getDocRoot("application/config/config.php"),
            "<?php if (!defined('BASEPATH')) {\n    exit('No direct script access allowed');\n}\n" .
                "// Configuración de LimeSurvey (creada por iaachz-panel)\n" .
                "return array(\n" .
                "    'components' => array(\n" .
                "        'db' => array(\n" .
                "            'connectionString' => " . $q("mysql:host=" . $db->host . ";port=3306;dbname=" . $db->name . ";") . ",\n" .
                "            'emulatePrepare' => true,\n" .
                "            'username' => " . $q($db->user) . ",\n" .
                "            'password' => " . $q($db->password) . ",\n" .
                "            'charset' => 'utf8mb4',\n" .
                "            'tablePrefix' => 'lime_',\n" .
                "        ),\n" .
                "        'urlManager' => array(\n" .
                "            'urlFormat' => 'get',\n" .
                "            'rules' => array(),\n" .
                "            'showScriptName' => true,\n" .
                "        ),\n" .
                "    ),\n" .
                "    'config' => array(\n" .
                "        'debug' => 0,\n" .
                "        'debugsql' => 0,\n" .
                "        'mysqlEngine' => 'InnoDB',\n" .
                "        'defaultlang' => " . $q((string) $options["language"]) . ",\n" .
                "    ),\n" .
                ");\n",
        );

        $this->runPanelJob("limesurvey", $this->appcontext->user(), $target->domain->domainName, [
            "php" => $options["php_version"],
            "adminuser" => $options["admin_username"],
            "adminpass" => $options["admin_password"],
            "adminemail" => $options["admin_email"],
            "fullname" => trim((string) ($options["admin_name"] ?? "")) ?: "Administrador",
        ]);
    }
}
