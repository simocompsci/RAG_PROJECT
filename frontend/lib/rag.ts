export type Source = {
  index: number;
  document_id: string;
  document_name: string;
  page: number;
  chunk_id: string;
  snippet: string;
  rrf_score: number;
};

export type QueryResponse = {
  answer: string;
  sources: Source[];
};

export type ChatMessage = {
  id: string;
  role: "user" | "assistant";
  content: string;
  sources?: Source[];
  isError?: boolean;
};

const apiBaseUrl = (
  process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8000"
).replace(/\/$/, "");

export async function askQuestion(question: string): Promise<QueryResponse> {
  const response = await fetch(`${apiBaseUrl}/api/query`, {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ question }),
  });

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    throw new Error(
      data?.message ?? "The assistant could not answer that question.",
    );
  }

  return data as QueryResponse;
}
