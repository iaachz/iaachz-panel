# Idiomas

La interfaz de iaachz-panel (menús, avisos, mensajes de error, pasos de las tareas y recomendaciones del Asesor) está en
**español** y puede mostrarse en otros idiomas. Ahora mismo hay: **Español** (idioma fuente), **English**, **Português** y **Français**.

## Cambiar el idioma

*Herramientas y ajustes → Idioma de la interfaz*, o por consola:

```bash
J='{"language":"en"}'      # es, en, pt, fr... o "auto"
sudo /usr/local/hestia/bin/v-hp settings-set "$(printf '%s' "$J" | base64 -w0)"
```

- **Un idioma concreto**: todos los usuarios ven el panel en ese idioma.
- **`auto`**: cada usuario ve el idioma de su perfil de Hestia (si no hay traducción, español).

Solo se traduce lo que escribe iaachz-panel. Las pantallas nativas de Hestia usan el idioma del perfil de cada usuario, como siempre.
Los informes HTML/PDF del Monitor y los complementos (`addons/`) se quedan en español por ahora.

## Cómo funciona

Cada idioma es un archivo `core/web/pro/lang/<código>.json` con `{"texto en español": "traducción"}`. El **texto en español es la clave**,
así que si falta una traducción se muestra el español. Lo usan los tres lenguajes del proyecto:

| Dónde | Cómo se escribe |
|---|---|
| PHP | `__("Guardar")`, `__e("Disco usado de %s", $x)` (con HTML escapado) |
| JavaScript | `HP.t('Guardar')`, `HP.t('%s sitios', n)` |
| Python | los mensajes de `HPError`, `jobs.step`, `fail` y el Asesor se traducen solos al salir (`hp/i18n.py`) |

Los mensajes con datos se escriben con `%s` en lugar de cada variable (`"No existe el dominio %s"`) y la traducción debe llevar los mismos `%s`.

## Añadir un idioma

```bash
python3 tools/i18n.py init de      # crea core/web/pro/lang/de.json con todos los textos vacíos
# ...rellena los valores...
python3 tools/i18n.py check de     # muestra lo que falta y los %s que no coinciden
```

Después añade el idioma a `HP_LANG_NAMES` en `core/web/pro/inc/i18n.php` (ya trae `de` e `it` listos para cuando exista su archivo)
y ejecuta `sudo ./install.sh` en el servidor. El idioma aparece solo en el selector en cuanto existe su archivo `.json`.

`python3 tools/i18n.py keys` lista todos los textos traducibles; `python3 tools/i18n.py check` sin argumentos revisa todos los idiomas.
