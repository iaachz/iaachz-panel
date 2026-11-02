#=========================================================================#
# iaachz-panel: Moodle (raíz public_html, hasta 5.0)         #
# Generada por iaachz-panel (Instalación rápida → Moodle). No editar: se     #
# regenera al reconstruir el dominio.                                     #
#=========================================================================#

server {
	listen      %ip%:%web_port%;
	server_name %domain_idn% %alias_idn%;
	root        %docroot%;
	index       index.php index.html index.htm;
	access_log  /var/log/nginx/domains/%domain%.log combined;
	access_log  /var/log/nginx/domains/%domain%.bytes bytes;
	error_log   /var/log/nginx/domains/%domain%.error.log error;

	include %home%/%user%/conf/web/%domain%/nginx.forcessl.conf*;

	client_max_body_size 512m;

	location ~ /\.(?!well-known\/) {
		deny all;
		return 404;
	}

	# Archivos internos de Moodle que no deben servirse
	location ~ (/vendor/|/node_modules/|composer\.json|/readme|/README|readme\.txt|/upgrade\.txt|/UPGRADING\.md|db/install\.xml|/fixtures/|/behat/|phpunit\.xml|\.lock|environment\.xml) {
		deny all;
		return 404;
	}

	location / {
		# Rutas del router de Moodle (4.5+): lo que no es un archivo va a r.php
		try_files $uri $uri/ /r.php$is_args$args;

		# PHP va primero: así /lib/javascript.php/…/archivo.js llega a PHP y no se trata como estático
		location ~ [^/]\.php(/|$) {
			fastcgi_split_path_info ^(.+\.php)(/.*)$;
			set $hp_path_info $fastcgi_path_info;
			try_files $fastcgi_script_name =404;

			include /etc/nginx/fastcgi_params;

			fastcgi_index index.php;
			fastcgi_param PATH_INFO $hp_path_info;
			fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
			fastcgi_param PHP_VALUE "max_input_vars=5000
upload_max_filesize=512M
post_max_size=512M";
			fastcgi_read_timeout 600s;

			fastcgi_pass %backend_lsnr%;

			include %home%/%user%/conf/web/%domain%/nginx.fastcgi_cache.conf*;
		}

		location ~* ^.+\.(jpeg|jpg|png|webp|gif|bmp|ico|svg|css|js|woff2?)$ {
			try_files $uri /r.php$is_args$args;
			expires 7d;
			fastcgi_hide_header "Set-Cookie";
		}
	}

	location /error/ {
		alias %home%/%user%/web/%domain%/document_errors/;
	}

	location /vstats/ {
		alias   %home%/%user%/web/%domain%/stats/;
		include %home%/%user%/web/%domain%/stats/auth.conf*;
	}

	include /etc/nginx/conf.d/phpmyadmin.inc*;
	include /etc/nginx/conf.d/phppgadmin.inc*;
	include %home%/%user%/conf/web/%domain%/nginx.conf_*;
}
