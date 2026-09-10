FROM python:3.12-slim

ARG MINERU_VERSION=3.4.4

ENV PYTHONDONTWRITEBYTECODE=1
ENV PYTHONUNBUFFERED=1
ENV MINERU_MODEL_SOURCE=local
ENV MINERU_API_OUTPUT_ROOT=/data/output
ENV MINERU_API_TASK_RETENTION_SECONDS=3600

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    fontconfig \
    fonts-noto-core \
    fonts-noto-cjk \
    libgl1 \
    libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

RUN python -m pip install --no-cache-dir --upgrade pip \
    && python -m pip install --no-cache-dir \
        torch==2.9.1 \
        torchvision==0.24.1 \
        --index-url https://download.pytorch.org/whl/cpu \
    && python -m pip install --no-cache-dir "mineru[pipeline]==${MINERU_VERSION}" \
    && mineru-models-download --source huggingface --model_type pipeline

# PaddleOCR's pipeline imports this compatibility module at runtime.
RUN python -m pip install --no-cache-dir six==1.17.0

RUN mkdir -p /data/output

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=5 \
    CMD curl -fsS http://localhost:8000/health || exit 1

CMD ["mineru-api", "--host", "0.0.0.0", "--port", "8000"]
