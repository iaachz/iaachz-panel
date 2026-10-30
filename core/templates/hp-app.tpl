#=========================================================================#
# iaachz-panel: aplicación Node.js / Python / Docker / proxy                #
# El destino (puerto o URL) vive en %home%/%user%/conf/web/%domain%/hp-app.conf
# y lo gestiona `v-hp app-*`. No edites este archivo.                     #
#=========================================================================#

server {
	listen      %ip%:%web_port%;
	server_name %domain_idn% %alias_idn%;
	root        %docroot%;
	access_log  /var/log/nginx/domains/%domain%.log combined;
	access_log  /var/log/nginx/domains/%domain%.bytes bytes;
	error_log   /var/log/nginx/domains/%domain%.error.log error;

	include %home%/%user%/conf/web/%domain%/nginx.forcessl.conf*;

	location ~ /\.(?!well-known\/) {
		deny all;
		return 404;
	}

	client_max_body_size 256m;

	include %home%/%user%/conf/web/%domain%/hp-app.conf*;

	location /error/ {
		alias %home%/%user%/web/%domain%/document_errors/;
	}

	location /vstats/ {
		alias   %home%/%user%/web/%domain%/stats/;
		include %home%/%user%/web/%domain%/stats/auth.conf*;
	}

	include %home%/%user%/conf/web/%domain%/nginx.conf_*;
}
