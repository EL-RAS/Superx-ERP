"use client";

import { useEffect, useRef, useState } from "react";
import type { ReactNode } from "react";
import { ChevronDown, Loader2, Search } from "lucide-react";

interface SearchableSelectProps<T> {
  /** id of the selected option, or "" when nothing is selected. */
  value: string;
  /** The selected option object, owned by the caller so it can read its fields. */
  selectedOption?: T | null;
  /** Called with the chosen option; the caller updates `value`/`selectedOption`. */
  onChange: (id: string, option: T) => void;
  /** Server-side search. `query` is "" for the default list shown on open. */
  fetchOptions: (query: string) => Promise<T[]>;
  /** Seeds the list (and the closed trigger label) before the first fetch. */
  initialOptions?: T[];
  getOptionId: (option: T) => string;
  renderOption: (option: T) => ReactNode;
  placeholder: string;
  searchPlaceholder?: string;
  emptyLabel: string;
  loadingLabel: string;
  disabled?: boolean;
  /** Pairs with a <label htmlFor>. */
  id?: string;
  className?: string;
}

/**
 * Async, server-searched combobox for large option lists.
 *
 * Replaces native <select> where the backing API is paginated: it fetches the
 * default list on open and re-queries (debounced) as the user types, so a
 * catalog of thousands of rows stays reachable without loading it all up front.
 */
export default function SearchableSelect<T>({
  value,
  selectedOption,
  onChange,
  fetchOptions,
  initialOptions,
  getOptionId,
  renderOption,
  placeholder,
  searchPlaceholder,
  emptyLabel,
  loadingLabel,
  disabled = false,
  id,
  className = "",
}: SearchableSelectProps<T>) {
  const rootRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  // Held in a ref so a caller that re-creates the callback each render cannot
  // restart the fetch loop.
  const fetchRef = useRef(fetchOptions);
  fetchRef.current = fetchOptions;
  const requestRef = useRef(0);

  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [options, setOptions] = useState<T[]>(initialOptions ?? []);
  const [loading, setLoading] = useState(false);
  // False until the first fetch settles, so the empty state cannot flash before
  // the default list has had a chance to arrive.
  const [loaded, setLoaded] = useState(false);
  const [active, setActive] = useState(-1);

  // Fetch the default list on open, then re-query as the user types.
  useEffect(() => {
    if (!open) return;

    const requestId = ++requestRef.current;
    const trimmed = query.trim();
    const isStale = () => requestRef.current !== requestId;

    const timer = setTimeout(() => {
      // setLoading lives inside the timer so the effect body never calls setState
      // synchronously; it also keeps the previous results visible while debouncing.
      setLoading(true);
      fetchRef
        .current(trimmed)
        .then((rows) => {
          if (!isStale()) setOptions(rows);
        })
        .catch(() => {
          if (!isStale()) {
            setOptions([]);
          }
        })
        .finally(() => {
          if (!isStale()) {
            setLoading(false);
            setLoaded(true);
            setActive(-1);
          }
        });
    }, trimmed ? 300 : 0);

    return () => clearTimeout(timer);
  }, [open, query]);

  // Close on outside press.
  useEffect(() => {
    if (!open) return;
    function onPointerDown(e: MouseEvent) {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) close();
    }
    document.addEventListener("mousedown", onPointerDown);
    return () => document.removeEventListener("mousedown", onPointerDown);
  }, [open]);

  function close() {
    setOpen(false);
    setQuery("");
    setActive(-1);
    // Abandon any in-flight request so the spinner cannot outlive the panel.
    requestRef.current++;
    setLoading(false);
  }

  function toggleOpen() {
    if (disabled) return;
    if (open) {
      close();
      return;
    }
    setOpen(true);
    // Focus after paint so the list is mounted.
    requestAnimationFrame(() => inputRef.current?.focus());
  }

  function choose(option: T) {
    onChange(getOptionId(option), option);
    close();
  }

  // Attached to the search input, which only mounts once the panel is open.
  function onKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === "Escape") {
      e.preventDefault();
      close();
      return;
    }
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setActive((i) => (i + 1 >= options.length ? 0 : i + 1));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActive((i) => (i <= 0 ? options.length - 1 : i - 1));
    } else if (e.key === "Enter") {
      e.preventDefault();
      const option = options[active];
      if (option) choose(option);
    }
  }

  return (
    <div ref={rootRef} className={`relative ${className}`}>
      <button
        type="button"
        id={id}
        disabled={disabled}
        onClick={toggleOpen}
        aria-haspopup="listbox"
        aria-expanded={open}
        className={`w-full flex items-center justify-between gap-2 px-4 py-2.5 bg-card/80 border border-border rounded-xl text-sm text-start focus:outline-none focus:border-border-hover transition-colors disabled:opacity-50 ${
          selectedOption ? "text-foreground" : "text-muted"
        }`}
      >
        <span className="truncate">
          {selectedOption ? renderOption(selectedOption) : placeholder}
        </span>
        {loading && open ? (
          <Loader2 className="w-4 h-4 shrink-0 animate-spin text-muted" />
        ) : (
          <ChevronDown
            className={`w-4 h-4 shrink-0 text-muted transition-transform ${open ? "rotate-180" : ""}`}
          />
        )}
      </button>

      {open && (
        <div className="absolute z-50 mt-1.5 w-full rounded-xl bg-card border border-border shadow-2xl shadow-black/20 overflow-hidden">
          <div className="flex items-center gap-2 px-3 py-2 border-b border-border">
            <Search className="w-4 h-4 shrink-0 text-muted" />
            <input
              ref={inputRef}
              type="text"
              value={query}
              placeholder={searchPlaceholder}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={onKeyDown}
              autoComplete="off"
              className="w-full bg-transparent text-sm text-foreground placeholder:text-muted focus:outline-none py-1"
            />
          </div>

          <div role="listbox" className="max-h-60 overflow-y-auto py-1">
            {!loaded || (loading && options.length === 0) ? (
              <p className="px-4 py-3 text-sm text-muted text-center">{loadingLabel}</p>
            ) : options.length === 0 ? (
              <p className="px-4 py-3 text-sm text-muted text-center">{emptyLabel}</p>
            ) : (
              options.map((option, index) => {
                const optionId = getOptionId(option);
                const isSelected = optionId === value;
                return (
                  <button
                    type="button"
                    key={optionId}
                    role="option"
                    aria-selected={isSelected}
                    onMouseEnter={() => setActive(index)}
                    onClick={() => choose(option)}
                    className={`w-full text-start px-4 py-2.5 text-sm transition-colors ${
                      index === active ? "bg-accent-dim" : "hover:bg-accent-dim"
                    } ${isSelected ? "text-primary" : "text-foreground"}`}
                  >
                    {renderOption(option)}
                  </button>
                );
              })
            )}
          </div>
        </div>
      )}
    </div>
  );
}
