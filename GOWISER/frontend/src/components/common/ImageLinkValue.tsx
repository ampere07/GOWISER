import React from 'react';
import { ExternalLink, Loader2 } from 'lucide-react';

interface ImageLinkValueProps {
  value?: string | null;
  isDarkMode: boolean;
}

/**
 * The value side of an image/document row in a details panel.
 *
 * - A link shows in full (wrapping, never cut off) and opens in a new tab.
 * - 'processing' is the placeholder the image queue writes while a file is still being
 *   uploaded to Google Drive. It shows as "Uploading…" with no link, since there is
 *   nothing to open yet.
 * - Anything else is shown as plain text.
 */
const ImageLinkValue: React.FC<ImageLinkValueProps> = ({ value, isDarkMode }) => {
  const text = (value || '').trim();

  if (!text) {
    return <span className={isDarkMode ? 'text-gray-500' : 'text-gray-400'}>-</span>;
  }

  if (text.toLowerCase() === 'processing') {
    return (
      <span
        className={`inline-flex items-center gap-1.5 text-sm ${isDarkMode ? 'text-amber-400' : 'text-amber-600'}`}
        title="The file is still being uploaded to Google Drive. Refresh in a few minutes."
      >
        <Loader2 size={14} className="animate-spin flex-shrink-0" />
        Uploading…
      </span>
    );
  }

  if (!/^https?:\/\//i.test(text)) {
    return <span className="break-all">{text}</span>;
  }

  return (
    <a
      href={text}
      target="_blank"
      rel="noopener noreferrer"
      title="Open in a new tab"
      className={`inline-flex items-start gap-1.5 break-all hover:underline ${isDarkMode ? 'text-blue-400 hover:text-blue-300' : 'text-blue-600 hover:text-blue-700'}`}
    >
      <span>{text}</span>
      <ExternalLink size={14} className="mt-1 flex-shrink-0" />
    </a>
  );
};

export default ImageLinkValue;
