# Ajustes por servidor

El código de iaachz-panel es el mismo en todos los servidores. Lo que cambia de uno a otro se guarda en
`/usr/local/hestia/data/hp/settings.json` (solo root) y se edita con `v-hp`.

## Claves

| Clave | Tipo | Por defecto | Para qué sirve |
|---|---|---|---|
| `public_ip` | texto | *(vacío = detectar)* | IP a la que deben apuntar los registros DNS de los túneles y en la que nginx escucha. |
| `hidden_domains` | lista | *(vacío)* | Dominios (y sus subdominios) que **no** se muestran en Sitios web y dominios ni en el asistente de túneles. Separados por coma. |
| `standalone_suffixes` | lista | *(vacío)* | Sufijos que forman su propio grupo en la lista de dominios, p. ej. `.vps.example.net`. |
| `tunnel_default_target` | texto | *(vacío)* | Destino (IP VPN) que aparece ya escrito al crear un túnel. |
| `tunnel_default_port` | texto | `8080` | Puerto que aparece ya escrito al crear un túnel. |
| `target_labels` | lista `ip=nombre` | *(vacío)* | Nombre amistoso para IPs VPN: `100.64.0.5=servidor-casa,100.64.0.9=nas`. |
| `pma_same_origin` | sí/no | `false` | `true` en servidores **privados**: phpMyAdmin se abre en el mismo origen y puerto que el panel, en vez de en el dominio web por el puerto 443. |

Además, las claves de alertas que ya edita *Herramientas y ajustes* en el panel (`alert_cpu`, `alert_mem`,
`alert_disk`, `alert_load`, `alert_ssl_days`, `alert_services`, `alert_apps`, `alert_email`, `metrics_enabled`).

> `v-hp settings-set` solo acepta claves que ya existen en la lista anterior; ignora el resto.

## Cómo cambiarlos

```bash
# Ver los ajustes actuales
sudo /usr/local/hestia/bin/v-hp settings-get

# Cambiar uno o varios (JSON codificado en base64)
J='{"public_ip":"203.0.113.10","hidden_domains":"ejemplo.com,otro.net"}'
sudo /usr/local/hestia/bin/v-hp settings-set "$(printf '%s' "$J" | base64 -w0)"
```

Los cambios se aplican de inmediato; no hace falta reiniciar nada.

## Ejemplos

**VPS público**

```json
{"public_ip": "203.0.113.10", "tunnel_default_target": "100.64.0.5", "target_labels": "100.64.0.5=servidor-casa"}
```

**Servidor privado, accesible solo por VPN**

```json
{"public_ip": "100.64.0.5", "pma_same_origin": true}
```

## Compatibilidad

El módulo de túneles también lee `/etc/iaachz-tunnels/settings.json` (clave `public_ip`) si existe, por
instalaciones anteriores. Los valores de `settings.json` de iaachz-panel tienen prioridad.
