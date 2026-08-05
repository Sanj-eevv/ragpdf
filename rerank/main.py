from fastapi import FastAPI
from pydantic import BaseModel
from sentence_transformers import CrossEncoder, SentenceTransformer

app = FastAPI()

# Loaded once at process startup, reused across requests.
model = CrossEncoder("cross-encoder/ms-marco-MiniLM-L-6-v2")
embedding_model = SentenceTransformer("all-MiniLM-L6-v2")


class RerankRequest(BaseModel):
    query: str
    documents: list[str]
    limit: int | None = None


class RankedResult(BaseModel):
    index: int
    document: str
    score: float


class EmbedRequest(BaseModel):
    inputs: list[str]


class EmbedResponse(BaseModel):
    embeddings: list[list[float]]


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/rerank", response_model=list[RankedResult])
def rerank(payload: RerankRequest) -> list[RankedResult]:
    pairs = [(payload.query, document) for document in payload.documents]
    scores = model.predict(pairs)

    ranked = sorted(
        (
            RankedResult(index=index, document=document, score=float(score))
            for index, (document, score) in enumerate(zip(payload.documents, scores))
        ),
        key=lambda result: result.score,
        reverse=True,
    )

    return ranked[: payload.limit] if payload.limit else ranked


@app.post("/embed", response_model=EmbedResponse)
def embed(payload: EmbedRequest) -> EmbedResponse:
    vectors = embedding_model.encode(payload.inputs, normalize_embeddings=True)

    return EmbedResponse(embeddings=[vector.tolist() for vector in vectors])
