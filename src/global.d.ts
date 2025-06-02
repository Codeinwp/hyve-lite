interface PostType {
	label: string;
	value: string;
}

interface HyveAssets {
	images: string;
}

interface HyveData {
	api: string;
	postTypes: PostType[];
	hasAPIKey: boolean;
	chunksLimit: number;
	isQdrantActive: boolean;
	assets: HyveAssets;
	stats: any; // Type this more specifically if you know the structure
	docs: string;
	qdrant_docs: string;
	pro: string;
}

declare global {
	interface Window {
		hyve: HyveData;
	}
}

export {};
