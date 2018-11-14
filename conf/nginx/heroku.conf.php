# Sets the worker threads to the number of CPU cores available in the system for best performance.
# Should be > the number of CPU cores.
# Maximum number of connections = worker_processes * worker_connections
# Default: 1
worker_processes auto;

# Maximum number of open files per worker process.
# Should be > worker_connections.
# Default: no limit
worker_rlimit_nofile 8192;

events {
	# If you need more connections than this, you start optimizing your OS.
	# That's probably the point at which you hire people who are smarter than you as this is *a lot* of requests.
	# Should be < worker_rlimit_nofile.
	# Default: 512
	worker_connections 8000;
}

http {
	# Hide nginx version information.
	# Default: on
	server_tokens off;

	# Specify MIME types for files.
	include mime.types;

	# Default: text/plain
	default_type application/octet-stream;

	# Update charset_types to match updated mime.types.
	# text/html is always included by charset module.
	# Default: text/html text/xml text/plain text/vnd.wap.wml application/javascript application/rss+xml
	charset_types
		text/css
		text/plain
		text/vnd.wap.wml
		application/javascript
		application/json
		application/rss+xml
		application/xml;

	# Include $http_x_forwarded_for within default format used in log files
	log_format	main	'$remote_addr - $remote_user [$time_local] "$request" '
                		'$status $body_bytes_sent "$http_referer" '
	                	'"$http_user_agent" "$http_x_forwarded_for"';

	# How long to allow each connection to stay idle.
	# Longer values are better for each individual client, particularly for SSL,
	# but means that worker connections are tied up longer.
	# Default: 75s
	keepalive_timeout 20s;

	# Speed up file transfers by using sendfile() to copy directly
	# between descriptors rather than using read()/write().
	# For performance reasons, on FreeBSD systems w/ ZFS
	# this option should be disabled as ZFS's ARC caches
	# frequently used files in RAM by default.
	# Default: off
	sendfile on;

	# Don't send out partial frames; this increases throughput
	# since TCP frames are filled up before being sent out.
	# Default: off
	tcp_nopush on;

	# Enable gzip compression.
	# Default: off
	gzip on;

	# Compression level (1-9).
	# 5 is a perfect compromise between size and CPU usage, offering about
	# 75% reduction for most ASCII files (almost identical to level 9).
	# Default: 1
	gzip_comp_level 5;

	# Don't compress anything that's already small and unlikely to shrink much
	# if at all (the default is 20 bytes, which is bad as that usually leads to
	# larger files after gzipping).
	# Default: 20
	gzip_min_length 256;

	# Compress data even for clients that are connecting to us via proxies,
	# identified by the "Via" header (required for CloudFront).
	# Default: off
	gzip_proxied any;

	# Tell proxies to cache both the gzipped and regular version of a resource
	# whenever the client's Accept-Encoding capabilities header varies;
	# Avoids the issue where a non-gzip capable client (which is extremely rare
	# today) would display gibberish if their proxy gave them the gzipped version.
	# Default: off
	gzip_vary on;

	# Compress all output labeled with one of the following MIME-types.
	# text/html is always compressed by gzip module.
	# Default: text/html
	gzip_types
		application/atom+xml
		application/javascript
		application/json
		application/ld+json
		application/manifest+json
		application/rss+xml
		application/vnd.geo+json
		application/vnd.ms-fontobject
		application/x-font-ttf
		application/x-web-app-manifest+json
		application/xhtml+xml
		application/xml
		font/opentype
		image/bmp
		image/svg+xml
		image/x-icon
		text/cache-manifest
		text/css
		text/plain
		text/vcard
		text/vnd.rim.location.xloc
		text/vtt
		text/x-component
		text/x-cross-domain-policy;

	# define an easy to reference name that can be used in fastgi_pass
	upstream heroku-fcgi {
		#server 127.0.0.1:4999 max_fails=3 fail_timeout=3s;
		server unix:/tmp/heroku.fcgi.<?=getenv('PORT')?:'8080'?>.sock max_fails=3 fail_timeout=3s;
		keepalive 16;
	}

	server {
		# define an easy to reference name that can be used in try_files
		location @heroku-fcgi {
			include fastcgi_params;

			fastcgi_buffers 256 4k;
			fastcgi_split_path_info ^(.+\.php)(/.*)$;
			fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
			# try_files resets $fastcgi_path_info, see http://trac.nginx.org/nginx/ticket/321, so we use the if instead
			fastcgi_param PATH_INFO $fastcgi_path_info if_not_empty;
			# pass actual request host instead of localhost
			fastcgi_param SERVER_NAME $host;

			if (!-f $document_root$fastcgi_script_name) {
				# check if the script exists
				# otherwise, /foo.jpg/bar.php would get passed to FPM, which wouldn't run it as it's not in the list of allowed extensions, but this check is a good idea anyway, just in case
				return 404;
			}

			fastcgi_pass heroku-fcgi;
		}

		server_name localhost;
		listen <?=getenv('PORT')?:'8080'?>;
		# FIXME: breaks redirects with foreman
		port_in_redirect off;

		root "<?=getenv('DOCUMENT_ROOT')?:getenv('HEROKU_APP_DIR')?:getcwd()?>";

		error_log stderr;
		access_log /tmp/heroku.nginx_access.<?=getenv('PORT')?:'8080'?>.log;

		include "<?=getenv('HEROKU_PHP_NGINX_CONFIG_INCLUDE')?>";

		# default handling of .php
		location ~ \.php {
			try_files @heroku-fcgi @heroku-fcgi;
		}
	}
}
