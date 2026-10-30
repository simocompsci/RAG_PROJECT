"use client";

import { Bubble, BubbleContent } from "@/components/ui/bubble";
import { Button } from "@/components/ui/button";
import {
  InputGroup,
  InputGroupAddon,
  InputGroupButton,
  InputGroupTextarea,
} from "@/components/ui/input-group";
import { Message, MessageContent } from "@/components/ui/message";
import {
  MessageScroller,
  MessageScrollerButton,
  MessageScrollerContent,
  MessageScrollerItem,
  MessageScrollerProvider,
  MessageScrollerViewport,
} from "@/components/ui/message-scroller";
import { askQuestion } from "@/lib/rag";
import type { ChatMessage, Source } from "@/lib/rag";
import {
  BotIcon,
  LoaderCircleIcon,
  SendHorizontalIcon,
} from "lucide-react";
import { useState } from "react";
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
    "Hi! I'm your AI assistant. Ask me a question a customer has brought to you, and I'll look it up in our internal procedures and policies.",
};

export default function Chat() {
  const [messages, setMessages] = useState<ChatMessage[]>([welcomeMessage]);
  const [isLoading, setIsLoading] = useState(false);

  async function send(question: string) {
    const trimmed = question.trim();

    if (trimmed === "" || isLoading) {
      return;
    }

    setMessages((current) => [
      ...current,
      { id: crypto.randomUUID(), role: "user", content: trimmed },
    ]);
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

  const hasConversation = messages.some((message) => message.role === "user");

  if (!hasConversation) {
    return (
      <div className="flex min-h-dvh flex-col bg-background text-foreground">
        <main className="mx-auto flex w-full max-w-3xl flex-1 items-center justify-center px-4 py-10 sm:px-6">
          <div className="flex w-full max-w-2xl flex-col gap-8">
            <div className="flex flex-col items-center gap-4 text-center">
              <div className="flex size-14 items-center justify-center rounded-[3px] bg-primary text-primary-foreground shadow-sm">
                <BotIcon className="size-10" />
              </div>
              <div className="space-y-2">
                <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">
                  Client Service Assistant
                </h1>
                <p className="mx-auto max-w-lg text-sm leading-relaxed text-muted-foreground sm:text-base">
                  {welcomeMessage.content}
                </p>
              </div>
            </div>

            <div className="space-y-3">
              <p className="text-center text-xs font-medium tracking-wide text-muted-foreground uppercase">
                Try asking
              </p>
              <div className="grid gap-2 sm:grid-cols-2">
                {starterQuestions.map((question) => (
                  <Button
                    key={question}
                    type="button"
                    variant="outline"
                    onClick={() => void send(question)}
                    className="h-auto justify-start whitespace-normal rounded-xl px-3 py-2.5 text-left text-sm font-normal text-muted-foreground"
                  >
                    {question}
                  </Button>
                ))}
              </div>
            </div>

            <ChatComposer onSend={send} isLoading={isLoading} autoFocus />
          </div>
        </main>
      </div>
    );
  }

  return (
    <div className="flex h-dvh flex-col bg-background text-foreground">
      <main className="mx-auto flex w-full max-w-3xl min-h-0 flex-1 flex-col px-4 sm:px-6">
        <div className="min-h-0 flex-1">
          <MessageScrollerProvider autoScroll defaultScrollPosition="end">
            <MessageScroller>
              <MessageScrollerViewport>
                <MessageScrollerContent className="py-6">
                  {messages.map((message) => (
                    <MessageScrollerItem
                      key={message.id}
                      messageId={message.id}
                      scrollAnchor
                    >
                      <ChatBubble message={message} />
                    </MessageScrollerItem>
                  ))}

                  {isLoading && (
                    <MessageScrollerItem messageId="loading" className="pb-1">
                      <Message align="start">
                        <MessageContent>
                          <Bubble align="start" variant="muted">
                            <BubbleContent className="flex items-center gap-2 text-muted-foreground">
                              <LoaderCircleIcon className="size-4 animate-spin" />
                              Searching the internal documents...
                            </BubbleContent>
                          </Bubble>
                        </MessageContent>
                      </Message>
                    </MessageScrollerItem>
                  )}
                </MessageScrollerContent>
              </MessageScrollerViewport>
              <MessageScrollerButton />
            </MessageScroller>
          </MessageScrollerProvider>
        </div>

        <ChatComposer onSend={send} isLoading={isLoading} />
      </main>
    </div>
  );
}

function ChatComposer({
  onSend,
  isLoading,
  autoFocus = false,
}: {
  onSend: (question: string) => Promise<void>;
  isLoading: boolean;
  autoFocus?: boolean;
}) {
  const [value, setValue] = useState("");

  async function submit() {
    const question = value.trim();

    if (question === "" || isLoading) {
      return;
    }

    setValue("");
    await onSend(question);
  }

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void submit();
  }

  function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key === "Enter" && !event.shiftKey) {
      event.preventDefault();
      void submit();
    }
  }

  return (
    <form onSubmit={handleSubmit} className="py-4">
      <InputGroup className="h-auto rounded-2xl bg-card p-2 shadow-sm">
        <InputGroupTextarea
          value={value}
          onChange={(event) => setValue(event.target.value)}
          onKeyDown={handleKeyDown}
          rows={2}
          autoFocus={autoFocus}
          placeholder="Ask a question from a customer..."
          disabled={isLoading}
          className="min-h-12 px-2 py-2 text-sm"
        />
        <InputGroupAddon
          align="block-end"
          className="justify-between border-t border-border px-1 pt-2"
        >
          <span className="text-[11px] text-muted-foreground">
            Enter to send · Shift + Enter for a new line
          </span>
          <InputGroupButton
            type="submit"
            variant="default"
            size="sm"
            disabled={isLoading || value.trim() === ""}
          >
            {isLoading ? "Searching..." : "Send"}
            <SendHorizontalIcon />
          </InputGroupButton>
        </InputGroupAddon>
      </InputGroup>
    </form>
  );
}

function ChatBubble({ message }: { message: ChatMessage }) {
  const isUser = message.role === "user";

  return (
    <Message align={isUser ? "end" : "start"}>
      <MessageContent>
        <Bubble
          align={isUser ? "end" : "start"}
          variant={message.isError ? "destructive" : isUser ? "default" : "muted"}
        >
          <BubbleContent>{message.content}</BubbleContent>
        </Bubble>

        {message.sources && message.sources.length > 0 && (
          <SourceList sources={message.sources} />
        )}
      </MessageContent>
    </Message>
  );
}

function SourceList({ sources }: { sources: Source[] }) {
  return (
    <div className="flex flex-col gap-2 pt-0.5">
      <p className="px-1 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
        Sources
      </p>

      {sources.map((source) => (
        <details
          key={source.chunk_id}
          className="group rounded-lg border border-border bg-card"
        >
          <summary className="flex cursor-pointer list-none items-center gap-2 px-3 py-2 text-xs text-card-foreground">
            <span className="flex size-5 shrink-0 items-center justify-center rounded-md bg-muted text-[10px] font-semibold text-muted-foreground">
              {source.index}
            </span>
            <span className="min-w-0 flex-1 truncate font-medium">
              {source.document_name}
            </span>
            <span className="shrink-0 text-muted-foreground">p. {source.page}</span>
            <span className="shrink-0 text-muted-foreground transition group-open:rotate-180">
              ▾
            </span>
          </summary>
          <p className="border-t border-border px-3 py-2.5 text-xs leading-relaxed text-muted-foreground">
            {source.snippet}
          </p>
        </details>
      ))}
    </div>
  );
}
