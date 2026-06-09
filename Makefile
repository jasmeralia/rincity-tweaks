WP_CONTENT  := /usr/local/lsws/wordpress/wp-content
PLUGINS     := $(WP_CONTENT)/plugins
MU_PLUGINS  := $(WP_CONTENT)/mu-plugins
OWNER       := nobody:nogroup

.PHONY: all rc_tweaks rincity-envira-zoom rincity-wordfence-temp-allowlist rincity-zero-scheduled-seconds help

all: rc_tweaks rincity-envira-zoom rincity-wordfence-temp-allowlist rincity-zero-scheduled-seconds ## Deploy all plugins

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*##' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*##"}; {printf "  %-40s %s\n", $$1, $$2}'

rc_tweaks: ## Deploy rc_tweaks to wp-content/plugins/rc_tweaks/
	sudo rsync -av --chown=$(OWNER) --exclude='README.md' rc_tweaks/ $(PLUGINS)/rc_tweaks/

rincity-envira-zoom: ## Deploy rincity-envira-zoom to wp-content/plugins/rincity-envira-zoom/
	sudo rsync -av --chown=$(OWNER) rincity-envira-zoom/ $(PLUGINS)/rincity-envira-zoom/

rincity-wordfence-temp-allowlist: ## Deploy rincity-wordfence-temp-allowlist to wp-content/mu-plugins/
	sudo install -o nobody -g nogroup -m 644 \
		rincity-wordfence-temp-allowlist/rincity-wordfence-temp-allowlist.php \
		$(MU_PLUGINS)/rincity-wordfence-temp-allowlist.php

rincity-zero-scheduled-seconds: ## Deploy rincity-zero-scheduled-seconds to wp-content/mu-plugins/
	sudo install -o nobody -g nogroup -m 644 \
		rincity-zero-scheduled-seconds/rincity-zero-scheduled-seconds.php \
		$(MU_PLUGINS)/rincity-zero-scheduled-seconds.php
