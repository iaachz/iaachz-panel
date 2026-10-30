#=========================================================================#
# Hestia Pro: nginx + Apache (modo proxy, como Plesk)                    #
# nginx sirve los estáticos y pasa el resto a Apache (127.0.0.1:7080); reglas en hp-apache.conf
# que gestiona `v-hp apache-*`. No edites este archivo.                     #
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

	location ~ /\.(?!well-known\/) {
		deny all;
		return 404;
	}

	client_max_body_size 256m;

	include %home%/%user%/conf/web/%domain%/hp-apache.conf*;

	location /error/ {
		alias %home%/%user%/web/%domain%/document_errors/;
	}

	location /vstats/ {
		alias   %home%/%user%/web/%domain%/stats/;
		include %home%/%user%/web/%domain%/stats/auth.conf*;
	}

	include %home%/%user%/conf/web/%domain%/nginx.conf_*;
}
