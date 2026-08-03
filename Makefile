WP_CONTENT  := /usr/local/lsws/wordpress/wp-content
PLUGINS     := $(WP_CONTENT)/plugins
MU_PLUGINS  := $(WP_CONTENT)/mu-plugins
OWNER       := nobody:nogroup

.PHONY: all rc_tweaks rincity-envira-exif rincity-envira-search-enhancements rincity-envira-zoom rincity-gallery-favorites rincity-image-size-control rincity-media-sync rincity-nav-tweaks rincity-news-widget rincity-wallpaper-candidates rincity-wf-block-logger rincity-wordfence-temp-allowlist rincity-zero-scheduled-seconds test help

all: rc_tweaks rincity-envira-exif rincity-envira-search-enhancements rincity-envira-zoom rincity-gallery-favorites rincity-image-size-control rincity-media-sync rincity-nav-tweaks rincity-news-widget rincity-wallpaper-candidates rincity-wf-block-logger rincity-wordfence-temp-allowlist rincity-zero-scheduled-seconds ## Deploy all plugins

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*##' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*##"}; {printf "  %-40s %s\n", $$1, $$2}'

rc_tweaks: ## Deploy rc_tweaks to wp-content/plugins/rc_tweaks/
	sudo rsync -av --chown=$(OWNER) --exclude='README.md' rc_tweaks/ $(PLUGINS)/rc_tweaks/

rincity-envira-exif: ## Deploy rincity-envira-exif to wp-content/plugins/rincity-envira-exif/
	sudo rsync -av --chown=$(OWNER) --exclude='README.md' rincity-envira-exif/ $(PLUGINS)/rincity-envira-exif/

rincity-envira-search-enhancements: ## Deploy rincity-envira-search-enhancements to wp-content/plugins/
	sudo rsync -av --chown=$(OWNER) --exclude='README.md' rincity-envira-search-enhancements/ $(PLUGINS)/rincity-envira-search-enhancements/

rincity-envira-zoom: ## Deploy rincity-envira-zoom to wp-content/plugins/rincity-envira-zoom/
	sudo rsync -av --chown=$(OWNER) rincity-envira-zoom/ $(PLUGINS)/rincity-envira-zoom/

rincity-gallery-favorites: ## Deploy rincity-gallery-favorites to wp-content/plugins/rincity-gallery-favorites/
	sudo rsync -av --chown=$(OWNER) rincity-gallery-favorites/ $(PLUGINS)/rincity-gallery-favorites/

rincity-image-size-control: ## Deploy rincity-image-size-control to wp-content/plugins/rincity-image-size-control/
	sudo rsync -av --chown=$(OWNER) rincity-image-size-control/ $(PLUGINS)/rincity-image-size-control/

rincity-media-sync: ## Deploy rincity-media-sync to wp-content/mu-plugins/
	sudo install -o nobody -g nogroup -m 644 \
		rincity-media-sync/rincity-media-sync.php \
		$(MU_PLUGINS)/rincity-media-sync.php
	sudo rsync -av --chown=$(OWNER) --delete \
		--exclude='README.md' --exclude='tests/' --exclude='rincity-media-sync.php' \
		rincity-media-sync/ $(MU_PLUGINS)/rincity-media-sync/
	sudo chmod 755 $(MU_PLUGINS)/rincity-media-sync/bin/rincity-media-sync-worker.sh
	sudo install -d -o nobody -g nogroup -m 755 \
		/var/tmp/rincity-media-sync/queue/put \
		/var/tmp/rincity-media-sync/queue/del \
		/var/tmp/rincity-media-sync/failed

rincity-nav-tweaks: ## Deploy rincity-nav-tweaks to wp-content/plugins/rincity-nav-tweaks/
	sudo rsync -av --chown=$(OWNER) --exclude='README.md' rincity-nav-tweaks/ $(PLUGINS)/rincity-nav-tweaks/

rincity-news-widget: ## Deploy rincity-news-widget to wp-content/plugins/rincity-news-widget/
	sudo rsync -av --chown=$(OWNER) --exclude='README.md' rincity-news-widget/ $(PLUGINS)/rincity-news-widget/

rincity-wallpaper-candidates: ## Deploy rincity-wallpaper-candidates to wp-content/plugins/rincity-wallpaper-candidates/
	sudo rsync -av --chown=$(OWNER) rincity-wallpaper-candidates/ $(PLUGINS)/rincity-wallpaper-candidates/

rincity-wf-block-logger: ## Deploy rincity-wf-block-logger to wp-content/mu-plugins/
	sudo install -o nobody -g nogroup -m 644 \
		rincity-wf-block-logger/rincity-wf-block-logger.php \
		$(MU_PLUGINS)/rincity-wf-block-logger.php

rincity-wordfence-temp-allowlist: ## Deploy rincity-wordfence-temp-allowlist to wp-content/mu-plugins/
	sudo install -o nobody -g nogroup -m 644 \
		rincity-wordfence-temp-allowlist/rincity-wordfence-temp-allowlist.php \
		$(MU_PLUGINS)/rincity-wordfence-temp-allowlist.php

rincity-zero-scheduled-seconds: ## Deploy rincity-zero-scheduled-seconds to wp-content/mu-plugins/
	sudo install -o nobody -g nogroup -m 644 \
		rincity-zero-scheduled-seconds/rincity-zero-scheduled-seconds.php \
		$(MU_PLUGINS)/rincity-zero-scheduled-seconds.php

test: ## Run dependency-free plugin tests
	php rincity-media-sync/tests/test-paths.php
