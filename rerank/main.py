from fastapi import FastAPI
from pydantic import BaseModel
from sentence_transformers import CrossEncoder

app = FastAPI()

# Loaded once at process startup, reused across requests.
model = CrossEncoder("cross-encoder/ms-marco-MiniLM-L-6-v2")


class RerankRequest(BaseModel):
    query: str
    documents: list[str]
    limit: int | None = None


class RankedResult(BaseModel):
    index: int
    document: str
    score: float


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
