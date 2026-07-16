FROM python:3.11-slim

WORKDIR /app

COPY services/ingestion-worker/pyproject.toml ./pyproject.toml
COPY services/ingestion-worker/src ./src

RUN python -m pip install --no-cache-dir --upgrade pip \
    && python -m pip install --no-cache-dir .

CMD ["isir-worker", "--consume-queue"]
