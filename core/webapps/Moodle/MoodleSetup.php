<?php

declare(strict_types=1);

namespace Hestia\WebApp\Installers\Moodle;

use Hestia\System\HestiaApp;
use Hestia\WebApp\BaseSetup;
use Hestia\WebApp\InstallationTarget\InstallationTarget;
use Hestia\WebApp\Installers\IaachzPanel\PanelJob;
use RuntimeException;

/**
 * iaachz-panel: Moodle con la versión a elegir.
 *
 * Hestia descarga el código, crea la base de datos y aplica la plantilla nginx (hp-moodle, o
 * hp-moodle-public para 5.1+, cuya raíz web es public/). Crear las tablas tarda varios minutos, así
 * que lo hace una tarea de iaachz-panel (`v-hp webapp-install moodle`); mientras tanto el sitio muestra
 * "Instalando…" (climaintenance.html) y la tarea también programa el cron de Moodle.
 */
class MoodleSetup extends BaseSetup {
    use PanelJob;

    // rama => [nombre, versiones de PHP admitidas, raíz web en public/]
    private const BRANCHES = [
        "stable503" => ["Moodle 5.3 (la más reciente)", ["8.3", "8.4"], true],
        "stable502" => ["Moodle 5.2", ["8.3", "8.4"], true],
        "stable501" => ["Moodle 5.1", ["8.2", "8.3", "8.4"], true],
        "stable500" => ["Moodle 5.0", ["8.2", "8.3", "8.4"], false],
        "stable405" => ["Moodle 4.5 LTS (soporte extendido)", ["8.1", "8.2", "8.3"], false],
    ];

    protected array $info = [
        "name" => "Moodle",
        "group" => "education",
        "version" => "4.5 LTS – 5.3",
        "thumbnail" => "moodle-logo.svg",
    ];

    protected array $config = [
        "form" => [
            "version" => [
                "type" => "select",
                "label" => "Versión de Moodle",
                "value" => "stable503",
                "options" => [],
            ],
            "site_name" => ["label" => "Nombre del sitio", "value" => "Campus virtual"],
            "short_name" => ["label" => "Nombre corto", "value" => "campus"],
            "language" => [
                "type" => "select",
                "label" => "Idioma",
                "value" => "es",
                "options" => [
                    "es" => "Español (internacional)",
                    "es_mx" => "Español (México)",
                    "en" => "English",
                    "pt_br" => "Português (Brasil)",
                    "fr" => "Français",
                ],
            ],
            "admin_username" => ["label" => "Usuario administrador", "value" => "admin"],
            "admin_password" => ["type" => "password", "label" => "Contraseña del administrador"],
            "admin_email" => ["label" => "Correo del administrador", "value" => ""],
        ],
        "database" => true,
        "resources" => [
            "archive" => ["src" => ""],
        ],
        "server" => [
            "nginx" => [
                "template" => "hp-moodle",
            ],
            "php" => [
                "supported" => ["8.1", "8.2", "8.3", "8.4"],
            ],
        ],
    ];

    public function __construct(HestiaApp $appcontext) {
        parent::__construct($appcontext);
        foreach (self::BRANCHES as $branch => [$label]) {
            $this->config["form"]["version"]["options"][$branch] = $label;
        }
    }

    public function install(InstallationTarget $target, array $options): void {
        $branch = (string) ($options["version"] ?? "");
        if (!isset(self::BRANCHES[$branch])) {
            throw new RuntimeException("Versión de Moodle no válida");
        }
        [$label, $php, $public] = self::BRANCHES[$branch];
        if (!in_array($options["php_version"] ?? "", $php, true)) {
            throw new RuntimeException(sprintf("%s necesita PHP %s (elegiste PHP %s)", $label, implode(", ", $php), $options["php_version"] ?? "?"));
        }
        $this->validate($options);

        $this->config["resources"]["archive"]["src"] = sprintf(
            "https://download.moodle.org/download.php/direct/%s/moodle-latest-%s.tgz",
            $branch,
            substr($branch, 6),
        );
        $this->config["server"]["nginx"]["template"] = $public ? "hp-moodle-public" : "hp-moodle";

        parent::install($target, $options);
    }

    private function validate(array $options): void {
        if (!preg_match('/^[a-z0-9_.@-]{1,100}$/', (string) ($options["admin_username"] ?? ""))) {
            throw new RuntimeException("Usuario administrador no válido: usa minúsculas, números y . _ - @");
        }
        $pass = (string) ($options["admin_password"] ?? "");
        if (strlen($pass) < 8 || !preg_match("/[0-9]/", $pass) || !preg_match("/[a-z]/", $pass) || !preg_match("/[A-Z]/", $pass) || !preg_match("/[^A-Za-z0-9]/", $pass)) {
            throw new RuntimeException("La contraseña del administrador debe tener 8 caracteres o más, con mayúscula, minúscula, número y un símbolo (política de Moodle)");
        }
        if (!filter_var((string) ($options["admin_email"] ?? ""), FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Escribe un correo válido para el administrador");
        }
        if (trim((string) ($options["site_name"] ?? "")) === "" || trim((string) ($options["short_name"] ?? "")) === "") {
            throw new RuntimeException("Escribe el nombre y el nombre corto del sitio");
        }
    }

    protected function setupApplication(InstallationTarget $target, array $options): void {
        // Hestia descomprime quitando la carpeta moodle/; por si acaso no lo hizo
        $files = $this->appcontext->listFiles($target->getDocRoot());
        if (in_array("moodle", $files, true)) {
            $this->appcontext->copyDirectory($target->getDocRoot("moodle/."), $target->getDocRoot());
            $this->appcontext->deleteDirectory($target->getDocRoot("moodle"));
            $files = $this->appcontext->listFiles($target->getDocRoot());
        }
        if (!in_array("config-dist.php", $files, true)) {
            throw new RuntimeException("No se pudo descomprimir Moodle en public_html");
        }

        $dataroot = $target->domain->domainPath . "/private/moodledata";
        $this->appcontext->addDirectory($dataroot);
        $this->appcontext->createFile(
            $dataroot . "/climaintenance.html",
            '<!doctype html><meta charset="utf-8"><title>Instalando Moodle</title>' .
                '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:15vh auto;text-align:center">' .
                "<h1>Instalando Moodle…</h1><p>El sitio estará listo en unos minutos. Vuelve a cargar la página más tarde.</p></div>\n",
        );
        $this->appcontext->createFile($target->getDocRoot("config.php"), $this->configPhp($target, $dataroot));

        $this->runDatabaseInstall($target, $options);
    }

    private function configPhp(InstallationTarget $target, string $dataroot): string {
        $db = $target->database;
        $q = fn(string $s): string => var_export($s, true);
        $dbtype = is_executable("/usr/bin/mariadb") ? "mariadb" : "mysqli";

        return "<?php  // Configuración de Moodle (creada por iaachz-panel)\n\n" .
            "unset(\$CFG);\nglobal \$CFG;\n\$CFG = new stdClass();\n\n" .
            "\$CFG->dbtype    = " . $q($dbtype) . ";\n" .
            "\$CFG->dblibrary = 'native';\n" .
            "\$CFG->dbhost    = " . $q($db->host) . ";\n" .
            "\$CFG->dbname    = " . $q($db->name) . ";\n" .
            "\$CFG->dbuser    = " . $q($db->user) . ";\n" .
            "\$CFG->dbpass    = " . $q($db->password) . ";\n" .
            "\$CFG->prefix    = 'mdl_';\n" .
            "\$CFG->dboptions = [\n    'dbpersist' => 0,\n    'dbport' => '',\n    'dbsocket' => '',\n    'dbcollation' => 'utf8mb4_unicode_ci',\n];\n\n" .
            "\$CFG->wwwroot   = " . $q($target->getUrl()) . ";\n" .
            "\$CFG->dataroot  = " . $q($dataroot) . ";\n" .
            "\$CFG->directorypermissions = 02770;\n" .
            "// nginx (plantilla hp-moodle) envía a r.php lo que no es un archivo\n" .
            "\$CFG->routerconfigured = true;\n\n" .
            "require_once(__DIR__ . '/lib/setup.php');\n\n" .
            "// No hay etiqueta de cierre de PHP a propósito.\n";
    }

    private function runDatabaseInstall(InstallationTarget $target, array $options): void {
        $this->runPanelJob("moodle", $this->appcontext->user(), $target->domain->domainName, [
            "php" => $options["php_version"],
            "lang" => $options["language"] ?? "es",
            "adminuser" => $options["admin_username"],
            "adminpass" => $options["admin_password"],
            "adminemail" => $options["admin_email"],
            "fullname" => $options["site_name"],
            "shortname" => $options["short_name"],
        ]);
    }
}
