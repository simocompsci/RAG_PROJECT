import Markdown, { type Components } from "react-markdown";
import remarkGfm from "remark-gfm";
import type { Root } from "mdast";
import { cn } from "cn";

import type { Source } from "@/lib/rag";

type MarkdownNode = {
  type: string;
  depth?: number;
  children?: MarkdownNode[];
};

function remarkBoldHeadings() {
  return (tree: Root) => {
    transformBoldHeadings(tree as unknown as MarkdownNode);
  };
}

function transformBoldHeadings(node: MarkdownNode) {
  if (!node.children) {
    return;
  }

  node.children = node.children.map((child) => {
    if (
      child.type === "paragraph" &&
      child.children?.length === 1 &&
      child.children[0]?.type === "strong" &&
      child.children[0].children?.length
    ) {
      return {
        type: "heading",
        depth: 3,
        children: child.children[0].children,
      };
    }

    transformBoldHeadings(child);

    return child;
  });
}

const markdownComponents: Components = {
  h1: ({ children }) => (
    <h1 className="mt-6 mb-3 text-xl font-semibold tracking-tight text-foreground first:mt-0">
      {children}
    </h1>
  ),
  h2: ({ children }) => (
    <h2 className="mt-6 mb-3 text-lg font-semibold tracking-tight text-foreground first:mt-0">
      {children}
    </h2>
  ),
  h3: ({ children }) => (
    <h3 className="mt-5 mb-2 text-base font-semibold tracking-tight text-foreground first:mt-0">
      {children}
    </h3>
  ),
  h4: ({ children }) => (
    <h4 className="mt-4 mb-2 text-sm font-semibold tracking-tight text-foreground first:mt-0">
      {children}
    </h4>
  ),
  p: ({ children }) => (
    <p className="my-3 text-[15px] leading-7 text-foreground/90 first:mt-0 last:mb-0">
      {children}
    </p>
  ),
  strong: ({ children }) => (
    <strong className="font-semibold text-foreground">{children}</strong>
  ),
  em: ({ children }) => <em className="italic text-foreground/90">{children}</em>,
  ul: ({ children }) => (
    <ul className="my-3 list-disc space-y-1.5 pl-5 text-[15px] leading-7 text-foreground/90 marker:text-muted-foreground first:mt-0 last:mb-0">
      {children}
    </ul>
  ),
  ol: ({ children }) => (
    <ol className="my-3 list-decimal space-y-1.5 pl-5 text-[15px] leading-7 text-foreground/90 marker:text-muted-foreground first:mt-0 last:mb-0">
      {children}
    </ol>
  ),
  li: ({ children }) => <li className="pl-0.5 [&>p]:my-0">{children}</li>,
  blockquote: ({ children }) => (
    <blockquote className="my-4 border-l-2 border-border pl-4 text-[15px] leading-7 text-muted-foreground italic">
      {children}
    </blockquote>
  ),
  code: ({ children }) => (
    <code className="rounded-md border border-border/70 bg-muted/70 px-1.5 py-0.5 font-mono text-[0.85em] text-foreground">
      {children}
    </code>
  ),
  pre: ({ children }) => (
    <pre className="my-4 overflow-x-auto rounded-xl border border-border bg-muted/40 p-4 font-mono text-[13px] leading-6 text-foreground [&>code]:border-0 [&>code]:bg-transparent [&>code]:p-0 [&>code]:text-inherit">
      {children}
    </pre>
  ),
  a: ({ href, children }) => {
    const isCitation = href?.startsWith("#source-");

    if (isCitation) {
      return (
        <a
          href={href}
          className="mx-0.5 inline-flex items-center rounded-md border border-border bg-muted/70 px-1 py-0.5 align-baseline text-[11px] font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        >
          {children}
        </a>
      );
    }

    return (
      <a
        href={href}
        target="_blank"
        rel="noreferrer"
        className="font-medium text-foreground underline underline-offset-4 decoration-border transition-colors hover:decoration-foreground"
      >
        {children}
      </a>
    );
  },
  table: ({ children }) => (
    <div className="my-4 w-full overflow-x-auto rounded-xl border border-border">
      <table className="w-full border-collapse text-left text-sm">{children}</table>
    </div>
  ),
  thead: ({ children }) => <thead className="bg-muted/60">{children}</thead>,
  th: ({ children }) => (
    <th className="border-b border-border px-3 py-2 font-semibold text-foreground">
      {children}
    </th>
  ),
  td: ({ children }) => (
    <td className="border-b border-border/70 px-3 py-2 align-top text-foreground/90 last:border-b-0">
      {children}
    </td>
  ),
  hr: () => <hr className="my-5 border-border" />,
  del: ({ children }) => (
    <del className="text-muted-foreground line-through">{children}</del>
  ),
};

function linkCitations(content: string, sources: Source[]): string {
  if (sources.length === 0) {
    return content;
  }

  const indexes = new Set(sources.map((source) => source.index));

  return content
    .split(/(```[\s\S]*?```|~~~[\s\S]*?~~~|`[^`\n]*`)/g)
    .map((segment, position) => {
      if (position % 2 === 1) {
        return segment;
      }

      return segment.replace(/\[(\d+)\]/g, (match, value: string) => {
        const index = Number(value);

        if (!indexes.has(index)) {
          return match;
        }

        return `[\\[${index}\\]](#source-${index})`;
      });
    })
    .join("");
}

export function ChatMarkdown({
  content,
  sources = [],
  className,
}: {
  content: string;
  sources?: Source[];
  className?: string;
}) {
  return (
    <div className={cn("min-w-0", className)}>
      <Markdown
        remarkPlugins={[remarkGfm, remarkBoldHeadings]}
        components={markdownComponents}
        skipHtml
      >
        {linkCitations(content, sources)}
      </Markdown>
    </div>
  );
}
