# laya-serve: Laya's Jev-compatible HTTP server (POST /v1/systemone, /v1/systemone/batch, GET /health).
#
# Built locally by `composer laya:up` (docker/compose.yaml); there is no published image.
# CPU torch by default; docker/compose.cuda.yaml rebuilds it against a CUDA wheel index.
FROM python:3.12-slim

ARG LAYA_VERSION=0.4.8
ARG TORCH_VERSION=2.14.1
ARG TORCH_INDEX_URL=https://download.pytorch.org/whl/cpu

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

# torch first, from the CPU (or CUDA) index, so laya's `torch>=2.0` requirement is already met
# and pip never pulls the multi-GB default CUDA wheel from PyPI.
#
# The optional `extra_ca` secret is for builds behind a TLS-inspecting proxy; it is never stored
# in a layer. Normal builds don't pass it.
RUN --mount=type=secret,id=extra_ca,required=false \
    if [ -s /run/secrets/extra_ca ]; then export PIP_CERT=/run/secrets/extra_ca; fi \
 && pip install --index-url "${TORCH_INDEX_URL}" "torch==${TORCH_VERSION}" \
 && pip install "laya[serve]==${LAYA_VERSION}" \
 && python -c "import laya.serve, fastapi, uvicorn, torch"

RUN useradd --create-home --uid 1000 --shell /usr/sbin/nologin laya \
 && mkdir -p /home/laya/.cache/huggingface \
 && chown -R laya:laya /home/laya/.cache

COPY healthcheck.py /opt/laya/healthcheck.py
# Fail the build if the probe's copy of laya's checkpoint names has drifted from this laya.
RUN python /opt/laya/healthcheck.py --verify-against-laya

USER laya
WORKDIR /home/laya
# Weights land here; compose mounts a named volume so they survive container rebuilds.
ENV HF_HOME=/home/laya/.cache/huggingface \
    LAYA_HOST=0.0.0.0 \
    LAYA_PORT=8000

EXPOSE 8000

# Healthy only once the checkpoints in LAYA_MODELS are resident (see healthcheck.py). The first
# start downloads the weights (~0.85 GB for english), hence the long start period; a
# successful probe ends it early.
HEALTHCHECK --interval=10s --timeout=5s --start-period=60m --retries=3 CMD python /opt/laya/healthcheck.py

CMD ["laya-serve"]
