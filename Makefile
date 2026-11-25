.PHONY: start stop test packages
start:
	./scripts/start.sh
stop:
	./scripts/stop.sh
test:
	./scripts/test.sh
packages:
	./scripts/build-zips.sh
