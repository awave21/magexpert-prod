FROM debian:trixie-slim
ENV DEBIAN_FRONTEND=noninteractive
RUN printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d \
    && chmod +x /usr/sbin/policy-rc.d \
    && apt-get update \
    && apt-get install -y --no-install-recommends postfix opendkim ca-certificates \
    && rm -rf /var/lib/apt/lists/*
COPY entrypoint.sh /usr/local/bin/mail-entrypoint
RUN chmod +x /usr/local/bin/mail-entrypoint
ENTRYPOINT ["mail-entrypoint"]
