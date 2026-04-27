.PHONY: run lint lint-conflicts lint-fast seed-admin rebuild

run:
	php -S localhost:8080 -t .

lint:
	@$(MAKE) lint-conflicts
	@$(MAKE) lint-fast
	@find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l > /dev/null
	@echo "All PHP files linted successfully."

lint-conflicts:
	@if rg -n --glob '*.php' '^(<<<<<<<|=======|>>>>>>>)' . > /tmp/php_conflict_markers.txt; then \
		echo "Merge conflict markers found in PHP files:"; \
		cat /tmp/php_conflict_markers.txt; \
		rm -f /tmp/php_conflict_markers.txt; \
		exit 1; \
	fi

lint-fast:
	@php -l lib/simple_pdf.php > /dev/null
	@php -l lib/analytics_report.php > /dev/null

seed-admin:
	php scripts/seed_admin.php

rebuild:
	php scripts/rebuild_app.php $(ARGS)
