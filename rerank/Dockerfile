FROM python:3.12-slim

WORKDIR /app

COPY ./rerank/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY ./rerank .

# Bake the model into the image at build time so containers don't hit the
# network on first request.
RUN python -c "from sentence_transformers import CrossEncoder; CrossEncoder('cross-encoder/ms-marco-MiniLM-L-6-v2')"

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
