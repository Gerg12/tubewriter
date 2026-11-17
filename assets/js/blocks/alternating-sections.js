const { registerBlockType } = wp.blocks;
const { InnerBlocks, InspectorControls, MediaUpload } = wp.blockEditor;
const { PanelBody, Button } = wp.components;

const ALLOWED_BLOCKS = ['custom-blocks/alternating-section-item'];

// Create a new block for individual section items
registerBlockType("custom-blocks/alternating-section-item", {
	title: "Alternating Section Item",
	icon: "align-pull-left",
	category: "common",
	parent: ['custom-blocks/alternating-sections'],
	attributes: {
		backgroundImage: {
			type: 'string',
			default: ''
		},
		imageAlt: {
			type: 'string',
			default: ''
		}
	},

	edit: (props) => {
		const {
			attributes: { backgroundImage, imageAlt },
			setAttributes,
		} = props;

		const ITEM_TEMPLATE = [
			['core/heading', {
				level: 3,
				className: 'header2-simple',
				placeholder: 'Enter section title...'
			}],
			['core/paragraph', {
				className: 'paragraph-sm',
				placeholder: 'Enter section text...'
			}],
			['core/button', {
				className: 'alternating-text-link',
				placeholder: 'Add link text...'
			}]
		];

		return (
			<div className="alternating-section-item">
				<div className="text-container">
					<div className="text-container-inner">
						<InnerBlocks
							template={ITEM_TEMPLATE}
							templateLock={false}
						/>
					</div>
				</div>
				<div className="image-container">
					{backgroundImage && (
						<img 
							src={backgroundImage} 
							alt={imageAlt}
							className="section-image"
						/>
					)}
				</div>
				<InspectorControls>
					<PanelBody title="Section Settings">
						<MediaUpload
							onSelect={(media) => setAttributes({ 
								backgroundImage: media.url,
								imageAlt: media.alt 
							})}
							type="image"
							render={({ open }) => (
								<Button onClick={open}>
									{backgroundImage ? 'Change Image' : 'Add Image'}
								</Button>
							)}
						/>
					</PanelBody>
				</InspectorControls>
			</div>
		);
	},

	save: (props) => {
		const {
			attributes: { backgroundImage, imageAlt },
		} = props;

		return (
			<div className="alternating-section-item">
				<div className="text-container">
					<div className="text-container-inner">
						<InnerBlocks.Content />
					</div>
				</div>
				<div className="image-container">
					{backgroundImage && (
						<img 
							src={backgroundImage} 
							alt={imageAlt}
							className="section-image"
						/>
					)}
				</div>
			</div>
		);
	},
});

// Main alternating sections container
registerBlockType("custom-blocks/alternating-sections", {
	title: "Alternating Sections",
	icon: "align-pull-left",
	category: "common",

	edit: () => {
		return (
			<section className="alternating-section no-gutter">
				<div className="container-full">
					<InnerBlocks
						allowedBlocks={ALLOWED_BLOCKS}
						template={[
							['custom-blocks/alternating-section-item']
						]}
					/>
				</div>
			</section>
		);
	},

	save: () => {
		return (
			<section className="alternating-section no-gutter">
				<div className="container-full">
					<InnerBlocks.Content />
				</div>
			</section>
		);
	},
});