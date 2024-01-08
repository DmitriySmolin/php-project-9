PORT ?= 8000
start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public
install:
	composer install
validate:
	composer validate
lint:
	composer exec --verbose phpcs -- --standard=PSR12 app public/index.php
lint-fix:
	composer exec --verbose phpcbf -- --standard=PSR12 app public/index.php
migrate:
	vendor/bin/phinx migrate
rollback:
	vendor/bin/phinx rollback



