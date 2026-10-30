"use client";

import { askQuestion } from "@/lib/rag";
import type { ChatMessage } from "@/lib/rag";
import { useEffect, useRef, useState } from "react";
import type { FormEvent, KeyboardEvent } from "react";

const starterQuestions = [
  "What is our refund policy for a damaged item?",
  "How do I escalate a customer complaint?",
  "What information do I need to verify a customer's account?",
  "What are the steps for processing a cancellation?",
];

const welcomeMessage: ChatMessage = {
  id: "welcome",
  role: "assistant",
  content:
    "Hi! I'm your client-service assistant. Ask me a question a customer has brought to you, and I'll look it up in our internal procedures and policies.",
};

export default function Chat() {
  const [messages, setMessages] = useState<ChatMessage[]>([welcomeMessage]);
  const [input, setInput] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const endRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: "smooth", block: "end" });
  }, [messages, isLoading]);

  async function send(question: string) {
    const trimmed = question.trim();

    if (trimmed === "" || isLoading) {
      return;
    }

    setMessages((current) => [
      ...current,
      { id: crypto.randomUUID(), role: "user", content: trimmed },
    ]);
    setInput("");
    setIsLoading(true);

    try {
      const response = await askQuestion(trimmed);

      setMessages((current) => [
        ...current,
        {
          id: crypto.randomUUID(),
          role: "assistant",
          content: response.answer,
          sources: response.sources,
        },
      ]);
    } catch (error) {
      setMessages((current) => [
        ...current,
        {
          id: crypto.randomUUID(),
          role: "assistant",
          content:
            error instanceof Error
              ? error.message
              : "Something went wrong. Please try again.",
          isError: true,
        },
      ]);
    } finally {
      setIsLoading(false);
    }
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void send(input);
  }

  function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key === "Enter" && !event.shiftKey) {
      event.preventDefault();
      void send(input);
    }
  }

  const showStarters = messages.length === 1 && !isLoading;

  return (
    <div className="flex h-dvh flex-col bg-zinc-950 text-zinc-100">
      <header className="border-b border-zinc-800 bg-zinc-950/80 backdrop-blur">
        <div className="mx-auto flex w-full max-w-4xl items-center gap-3 px-4 py-4 sm:px-6">
          <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500 font-semibold text-emerald-950">
            CS
          </div>
          <div className="min-w-0">
            <h1 className="truncate text-sm font-semibold sm:text-base">
              Client Service Assistant
            </h1>
            <p className="truncate text-xs text-zinc-400">
              Answers grounded in internal procedures and policies
            </p>
          </div>
          <span className="ml-auto hidden items-center gap-2 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-3 py-1 text-xs text-emerald-300 sm:flex">
            <span className="size-1.5 rounded-full bg-emerald-400" />
            Internal knowledge base
          </span>
        </div>
      </header>

      <main className="mx-auto flex w-full max-w-4xl flex-1 flex-col overflow-hidden px-4 sm:px-6">
        <div className="flex-1 space-y-6 overflow-y-auto py-6">
          {messages.map((message) => (
            <Message key={message.id} message={message} />
          ))}

          {isLoading && (
            <div className="flex items-center gap-2 text-sm text-zinc-400">
              <span className="flex gap-1">
                <span className="size-1.5 animate-bounce rounded-full bg-zinc-500 [animation-delay:-0.3s]" />
                <span className="size-1.5 animate-bounce rounded-full bg-zinc-500 [animation-delay:-0.15s]" />
                <span className="size-1.5 animate-bounce rounded-full bg-zinc-500" />
              </span>
              Searching the internal documents...
            </div>
          )}

          <div ref={endRef} />
        </div>

        {showStarters && (
          <div className="grid gap-2 pb-4 sm:grid-cols-2">
            {starterQuestions.map((question) => (
              <button
                key={question}
                type="button"
                onClick={() => void send(question)}
                className="rounded-xl border border-zinc-800 bg-zinc-900/50 px-3 py-2.5 text-left text-sm text-zinc-300 transition hover:border-zinc-700 hover:bg-zinc-900 hover:text-zinc-100"
              >
                {question}
              </button>
            ))}
          </div>
        )}

        <form onSubmit={handleSubmit} className="pb-4 sm:pb-6">
          <div className="rounded-2xl border border-zinc-800 bg-zinc-900 p-2 shadow-lg shadow-black/20 focus-within:border-emerald-500/50">
            <textarea
              value={input}
              onChange={(event) => setInput(event.target.value)}
              onKeyDown={handleKeyDown}
              rows={2}
              placeholder="Ask a question from a customer..."
              disabled={isLoading}
              className="w-full resize-none bg-transparent px-3 py-2 text-sm text-zinc-100 outline-none placeholder:text-zinc-500 disabled:opacity-60"
            />
            <div className="flex items-center justify-between gap-3 px-2 pb-1">
              <p className="text-[11px] text-zinc-500">
                Enter to send · Shift + Enter for a new line
              </p>
              <button
                type="submit"
                disabled={isLoading || input.trim() === ""}
                className="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-medium text-emerald-950 transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-40"
              >
                {isLoading ? "Searching..." : "Send"}
              </button>
            </div>
          </div>
        </form>
      </main>
    </div>
  );
}

function Message({ message }: { message: ChatMessage }) {
  const isUser = message.role === "user";

  return (
    <div className={isUser ? "flex justify-end" : "flex justify-start"}>
      <div
        className={
          isUser
            ? "max-w-[85%] rounded-2xl rounded-br-md bg-emerald-500 px-4 py-3 text-sm text-emerald-950"
            : `max-w-[92%] rounded-2xl rounded-bl-md border px-4 py-3 ${
                message.isError
                  ? "border-red-500/30 bg-red-500/10 text-red-200"
                  : "border-zinc-800 bg-zinc-900 text-zinc-100"
              }`
        }
      >
        <p className="whitespace-pre-wrap leading-relaxed">{message.content}</p>

        {message.sources && message.sources.length > 0 && (
          <div className="mt-4 space-y-2 border-t border-zinc-800 pt-3">
            <p className="text-[11px] font-medium uppercase tracking-wide text-zinc-500">
              Sources
            </p>

            {message.sources.map((source) => (
              <details
                key={source.chunk_id}
                className="group rounded-xl border border-zinc-800 bg-zinc-950/60"
              >
                <summary className="flex cursor-pointer list-none items-center gap-2 px-3 py-2 text-xs text-zinc-300">
                  <span className="flex size-5 shrink-0 items-center justify-center rounded-md bg-zinc-800 text-[10px] font-semibold text-zinc-300">
                    {source.index}
                  </span>
                  <span className="min-w-0 flex-1 truncate font-medium">
                    {source.document_name}
                  </span>
                  <span className="shrink-0 text-zinc-500">p. {source.page}</span>
                  <span className="ml-1 shrink-0 text-zinc-600 transition group-open:rotate-180">
                    ▾
                  </span>
                </summary>
                <p className="border-t border-zinc-800 px-3 py-2.5 text-xs leading-relaxed text-zinc-400">
                  {source.snippet}
                </p>
              </details>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
